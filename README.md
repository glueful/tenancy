# glueful/tenancy

Shared-database, row-level multi-tenancy for the [Glueful](https://github.com/glueful/framework) framework.

## What it is

`glueful/tenancy` gives a single application the ability to serve many tenants out of **one shared
database**, isolating their data at the **row level**: tenant-owned tables carry a `tenant_uuid`
column, and every read/write against those tables is automatically scoped to the active tenant.

The data model is **many-tenants-per-user**: a (global) user is granted a role inside a tenant through
a `tenant_memberships` bridge row, so one user can belong to several tenants and one tenant can have
many members. This is **logical isolation, not hard isolation** — see [Security posture](#security-posture).

| Concern | Mechanism |
| --- | --- |
| Resolve the active tenant from a request | `tenant` middleware + resolver chain |
| Scope reads / stamp writes on tenant tables | `BelongsToTenant` trait (ORM global scope + create/update hooks) |
| Backstop for raw (non-ORM) SQL | Connection table hook + `TenantQueryGuard` interceptor |
| Step outside the scope deliberately | `Tenancy::runAsTenant / runAsSystem / forAnyTenant` |
| Carry the tenant into jobs / CLI / scheduler | `PropagatesTenant`, `RunsInTenantContext`, `ForEachTenant` |
| Operate tenants | `tenant:create|list|activate|suspend|diagnose` |

## Install / enable

```bash
composer require glueful/tenancy
php glueful migrate:run
```

`migrate:run` applies the two FOUNDATION migrations that create the `tenants` and `tenant_memberships`
tables. If your app uses an explicit extension allow-list, enable it there:

```bash
php glueful extensions:enable Tenancy
```

**Framework requirement:** `glueful/framework ^1.53.0`. The extension relies on framework seams shipped
in **1.53.0** — the chainable `Connection::addTableHook()` / `QueryExecutor::addQueryInterceptor()`
seams (so the tenancy hooks compose with host interceptors instead of replacing them) and the
`Connection::class` container binding (the `tenant:*` commands resolve the database via `db()`). The
tenant predicate is written **unqualified** (see [Automatic scoping](#automatic-scoping)) so it works on
the framework's UPDATE/DELETE path **without** depending on the qualified-column validator fix. Earlier
framework versions do not expose these seams and are not supported.

## The data model

Two registry tables ship with the extension (both are **central / never tenant-scoped**):

**`tenants`** — the tenant directory.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint, PK, auto-increment | |
| `uuid` | string(12), unique | stable public principal id used across the system |
| `slug` | string(255), unique | human-facing key |
| `name` | string(255) | display name |
| `status` | string(32), default `active` | `active` resolves; anything else (e.g. `suspended`) does not |
| `settings` | text, nullable | per-tenant JSON blob |
| `created_at` / `updated_at` | timestamp | DB `CURRENT_TIMESTAMP` defaults |
| `deleted_at` | timestamp, nullable | soft delete |

**`tenant_memberships`** — grants a global user a role in a tenant.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint, PK, auto-increment | |
| `uuid` | string(12), unique | |
| `tenant_uuid` | string(12) | FK → `tenants(uuid)`, cascade on delete |
| `user_uuid` | string(12) | indexed only — external principal id, **no** FK (the user store is a separate package) |
| `role` | string(64), default `member` | one of `owner`, `admin`, `member`, `viewer` (configurable) |
| `status` | string(32), default `active` | |
| | | `unique(tenant_uuid, user_uuid)` — one membership per user per tenant |

`Glueful\Extensions\Tenancy\Models\Tenant` and `Glueful\Extensions\Tenancy\Models\TenantMembership`
model these. Their consumer-side counterpart is **your own tenant-owned model** — any model that opts
in via `BelongsToTenant`.

### Making a consumer table tenant-owned

Add a `tenant_uuid` column to the table and the trait to the model:

```php
// migration
$schema->createTable('projects', function ($table) {
    $table->bigInteger('id')->primary()->autoIncrement();
    $table->string('uuid', 12);
    $table->string('tenant_uuid', 12);          // <-- tenant ownership column
    $table->string('slug', 255);
    $table->string('name', 255);
    $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

    $table->unique('uuid');
    $table->index('tenant_uuid');

    // ⚠ per-tenant-unique business key MUST be COMPOSITE — see below
    $table->unique(['tenant_uuid', 'slug']);
});
```

```php
use Glueful\Database\ORM\Model;
use Glueful\Extensions\Tenancy\ORM\Concerns\BelongsToTenant;

final class Project extends Model
{
    use BelongsToTenant;

    protected string $table = 'projects';

    protected array $fillable = ['slug', 'name'];
    // tenant_uuid does NOT need to be in $fillable — it is force-stamped on create.
}
```

Also list every tenant-owned table in `config/tenancy.php` under `tables` — that list is the
**authoritative** registry the raw-query backstop reads at boot (the trait also self-registers, but the
config list is what protects a table before its model is booted):

```php
'tables' => ['projects'],
```

### ⚠ The composite-unique pitfall

A per-tenant-unique business key (a slug, an order number, an email-within-tenant) **must be a composite
unique on `(tenant_uuid, key)`** — never a global unique on the key alone:

```php
$table->unique(['tenant_uuid', 'slug']);   // ✅ each tenant may use 'flagship'
// $table->unique('slug');                   // ❌ tenant B can never reuse a slug tenant A took
```

A global `unique('slug')` leaks across the tenant boundary: as soon as tenant A creates a project with
slug `flagship`, tenant B is permanently blocked from using it — and the failure surfaces as a confusing
database constraint violation, not a tenancy error. Always scope uniqueness by `tenant_uuid`.

## Request flow

Register the `tenant` middleware on tenant-scoped routes. It must run **after** authentication (it reads
`auth.user.uuid` to check membership):

```php
$router->group(['middleware' => ['auth', 'tenant']], function ($router) {
    $router->get('/projects', [ProjectController::class, 'index']);
});

// A central/optional route tolerates a missing tenant:
$router->get('/account', [AccountController::class, 'show'])->middleware(['auth', 'tenant:optional']);
```

On each request the middleware: **resolve** the tenant candidate (resolver chain) → **validate** it
exists, is active, and that the user is a member (or holds a bypass permission) → **set** the request
tenant context → run the handler → **clear** the context in a `finally` (state never leaks to a later
request, even on success).

Responses:

| Situation | Status |
| --- | --- |
| Tenant unknown **or** inactive (suspended/soft-deleted) | **404** — the two are never distinguished, so existence is not leaked |
| Tenant known, authenticated user is not a member | **403** |
| Above 403, with `hide_existence` enabled | collapsed to **404** so membership cannot be probed |

### Resolver chain

Resolvers run in the configured order; the **first non-null candidate wins**. Configure order and each
resolver's input in `config/tenancy.php`:

```php
'resolvers' => ['subdomain', 'path', 'header', 'query', 'jwt', 'active_session'],

'subdomain' => ['base_domain' => env('TENANCY_BASE_DOMAIN')], // acme.app.com → acme
'path'      => ['segment' => 't'],                            // /t/acme/...   → acme
'header'    => ['name' => 'X-Tenant-Id'],                     // request header
'query'     => ['name' => 'tenant_id'],                       // ?tenant_id=acme
'jwt'       => ['claim' => 'tenant_id'],                      // jwt.claims[tenant_id]
// 'active_session' reads the 'tenancy.active_tenant' request attribute (UX/session-driven)
```

| Resolver | Reads |
| --- | --- |
| `subdomain` | left-most subdomain label of the host (requires `subdomain.base_domain`) |
| `path` | leading `/<segment>/<tenant>/...` path segment |
| `header` | the configured request header (`header.name`) |
| `query` | the configured query parameter (`query.name`) |
| `jwt` | the configured claim from the `jwt.claims` request attribute |
| `active_session` | the `tenancy.active_tenant` request attribute |

Trim the list to only the resolvers you use; unknown names are skipped rather than erroring.

## Automatic scoping

Adding `BelongsToTenant` to a model wires three behaviors against the model's request-scoped tenant
context:

- **Reads are scoped.** A global scope appends `where tenant_uuid = <current tenant>` to every query.
  With no active tenant and `enforcement.required_by_default` on, the scope **fails closed** —
  `MissingTenantContextException` rather than ever returning unscoped rows.
- **Creates are force-stamped.** A `creating` hook sets `tenant_uuid` from the active tenant via
  `setAttribute()`, which **bypasses `$fillable`**. The stamped value **unconditionally overwrites** any
  caller-supplied `tenant_uuid` (e.g. from a mass-assigned request body), so a model can never plant a
  row in another tenant — even if it lists `tenant_uuid` in `$fillable` or is unguarded.
- **`tenant_uuid` is immutable.** An `updating` hook rejects any change to `tenant_uuid` on a model save.

```php
// Inside a tenant request (context set by the `tenant` middleware):
Project::query($context)->get();              // only this tenant's projects
Project::create($context, ['slug' => 'app']); // tenant_uuid stamped automatically
```

### Raw / non-ORM access

The ORM scope only covers Model-path queries. For hand-written query-builder code, two backstops apply:

1. **Auto-injection table hook** — a `Connection` table hook injects `where tenant_uuid = <current>`
   into any query against a registered tenant-owned table while a tenant is active and no bypass is set.
2. **Pre-execution guard** — `TenantQueryGuard` inspects the final SQL just before execution and, if it
   sees unscoped access to a tenant-owned table, **throws in dev/test** (`guard.dev = throw`) or **emits
   a metric/log in prod** (`guard.prod = metric`). It is conservative (prefers letting a query through
   over a false-positive outage) and is a no-op outside a tenant request or under a bypass.

For deliberate raw access, use the provided helper, which asserts the table is registered and returns
the already-scoped builder:

```php
use Glueful\Extensions\Tenancy\Query\TenantQuery;

$rows = TenantQuery::tenantTable($context, 'projects')->where('archived', false)->get();
```

**Rule of thumb: use models or `TenantQuery`; the guard catches the rest.**

## Bypass APIs (noisy on purpose)

`Glueful\Extensions\Tenancy\Bypass\Tenancy` is the only sanctioned way to step outside the per-request
scope. The names are intentionally explicit — there is **no generic `withoutScope()`** — so a bypass is
always obvious in a diff and in a security review. Each method saves, sets, and restores tenancy state in
a `finally`, so they nest and unwind cleanly even on exception.

```php
use Glueful\Extensions\Tenancy\Bypass\Tenancy;

// Act AS a specific tenant: queries scope to it, writes stamp it, no bypass active.
// Accepts a Tenant, or a uuid/slug string (resolved + active-checked).
Tenancy::runAsTenant('acme', function () {
    Project::create($context, ['slug' => 'q3']);
});

// System / no-tenant privileged maintenance (migrations, schedulers, cross-tenant admin):
Tenancy::runAsSystem(function () {
    // runs unscoped, no active tenant
});

// Cross-tenant READ — scoped reads suspended so every tenant's rows are visible.
Tenancy::forAnyTenant(function () {
    return Project::query($context)->get();
});
```

| Method | When to use |
| --- | --- |
| `runAsTenant(Tenant\|string $tenant, callable $fn)` | act as one specific tenant |
| `runAsSystem(callable $fn)` | trusted system / no-tenant maintenance |
| `forAnyTenant(callable $fn, bool $requirePermission = true, ?TenantAccess $access = null)` | cross-tenant read |
| `registerTable(string $table)` | register a table as tenant-owned (delegates to the registry) |

**`forAnyTenant` is permission-gated on request paths.** By default it checks whether the current user
holds any of `config('tenancy.bypass_permissions')` (default `tenancy.access_any`, `tenancy.manage`) and
throws `TenantAccessDeniedException` if not — failing closed when authorization cannot be evaluated.
Trusted CLI / system callers pass `$requirePermission = false` to skip the check.

The check honors your app's **active permission provider** first (`PermissionManager::can()` — the same
authority the rest of the app uses), then falls back to the framework `Gate`'s voters when no provider is
active. So an RBAC extension like **`glueful/aegis`** governs bypass directly: grant a role
`tenancy.access_any` / `tenancy.manage` in aegis and it unlocks cross-tenant access. With no provider
installed, a configured `super_roles` user (or a `config/permissions.php` policy) grants it via the Gate.

## Context propagation

The `tenant` middleware only sets the tenant for HTTP requests. Outside the request lifecycle, propagate
the tenant explicitly:

**Jobs** — opt in per job with `PropagatesTenant`. Capture the tenant at dispatch (it is stored in the
job's serialization-surviving payload) and restore it on the worker (where it is re-loaded and
**re-validated as active**; a missing/inactive tenant throws rather than running unscoped):

```php
use Glueful\Queue\Job;
use Glueful\Extensions\Tenancy\Queue\PropagatesTenant;

final class SendInvoice extends Job
{
    use PropagatesTenant;

    public function __construct(array $data = [], ?ApplicationContext $context = null)
    {
        parent::__construct($data, $context);
        $this->captureTenantContext($context); // runs inside the request
    }

    public function handle(): void
    {
        $this->runInTenantContext(function (): void {
            // tenant-scoped work — DB guard / auto-injection now see the tenant
        });
    }
}
```

No captured tenant ⇒ the job runs **system-scoped** (no tenant, no implicit bypass).

**CLI** — add the `--tenant` switch with `RunsInTenantContext`:

```php
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Tenancy\Console\Concerns\RunsInTenantContext;

final class BuildReports extends BaseCommand
{
    use RunsInTenantContext;

    protected function configure(): void
    {
        $this->setName('reports:build');
        $this->configureTenantOption();          // adds --tenant
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runInTenantContext($input, function (): int {
            // scoped to --tenant, or trusted SYSTEM context when the flag is omitted
            return self::SUCCESS;
        });
    }
}
```

`--tenant=<uuid|slug>` resolves + active-validates a single tenant; **no flag = trusted system context**
(no tenant, enforcement suspended).

**Scheduler** — fan a scheduled callback out over every active tenant with `ForEachTenant`:

```php
use Glueful\Extensions\Tenancy\Scheduling\ForEachTenant;
use Glueful\Extensions\Tenancy\Models\Tenant;

ForEachTenant::run($context, function (Tenant $tenant): void {
    // tenant-scoped maintenance for $tenant; inactive tenants are skipped
});
```

## Console commands

```bash
php glueful tenant:create --slug=acme --name="Acme Inc" [--status=active]   # register a tenant
php glueful tenant:list                                                     # table of all tenants
php glueful tenant:activate <slug>                                          # set status = active
php glueful tenant:suspend <slug>                                           # set status = suspended (still exists, won't resolve)
php glueful tenant:diagnose                                                 # health report (read-only)
```

`tenant:diagnose` reports three sections:

- **Registered tenant-owned tables** — every table in the registry (config `tables` + trait-registered).
- **Schema drift** — each registered table is checked for a `tenant_uuid` column; any missing column (or
  a registered table that does not exist) is flagged.
- **Membership integrity** — counts orphan `tenant_memberships` rows whose `tenant_uuid` has no matching
  `tenants.uuid`.

It is a report, not a gate — it always exits success, but renders warnings prominently. Run it after
adding tenant-owned tables.

## Security posture

This is **logical isolation, not hard isolation.** All tenants share one database; isolation is enforced
in the **application layer** by three cooperating mechanisms — the ORM scope, the create-time
force-stamp, and the raw-query guard.

**Threat model — be honest about what it does and does not cover:**

- ✅ **Protects against application-level cross-tenant access** — the common SaaS need. Model queries are
  scoped, raw builder queries are auto-injected and guarded, writes are force-stamped to the active
  tenant, and `tenant_uuid` is immutable.
- ❌ **Does NOT protect a SQL path that bypasses the framework query layer.** A hand-written `PDO`
  statement, a query the guard's conservative heuristic does not recognize, or anything executed outside
  the builder is **not** scoped. The guard is a safety net, not a wall.
- ❌ **Does NOT defend against a compromised database credential.** Anyone with direct DB access sees
  every tenant's rows — there is no per-tenant database, schema, or encryption boundary here. If you need
  isolation against that, you need a different (physical) tenancy model.

**Fail-closed defaults:**

- `enforcement.required_by_default = true` — a tenant-owned model with no active tenant **throws** rather
  than returning unscoped rows.
- Writes are **force-stamped** to the active tenant, overriding any caller-supplied `tenant_uuid`.
- The guard **throws in dev/test** so leaks are caught loudly during development.
- `forAnyTenant` on a request is **permission-gated** and fails closed when authorization is unavailable.

**Recommendations:**

- Make every per-tenant-unique key a **composite unique on `(tenant_uuid, …)`** (see the pitfall above).
- List every tenant-owned table in `config/tenancy.php` `tables` so the backstop protects it from boot.
- Run `php glueful tenant:diagnose` after schema changes to catch drift and orphaned memberships.
- Keep `enforcement.guard.prod` at `metric` (or `log`) so prod leaks are observable without risking an
  outage; keep `guard.dev` at `throw`.
- Enable `enforcement.hide_existence` if even tenant membership must not be probable.

## Configuration reference

`config/tenancy.php` (merged from the extension; override per app):

| Key | Default | Env | Purpose |
| --- | --- | --- | --- |
| `enabled` | `true` | | master switch; `false` fully disarms the query guard |
| `resolvers` | `['subdomain','path','header','query','jwt','active_session']` | | resolver precedence (first non-null wins) |
| `subdomain.base_domain` | `null` | `TENANCY_BASE_DOMAIN` | base host for subdomain resolution |
| `path.segment` | `'t'` | | leading path segment |
| `header.name` | `'X-Tenant-Id'` | | tenant header |
| `query.name` | `'tenant_id'` | | tenant query param |
| `jwt.claim` | `'tenant_id'` | | JWT claim name |
| `tables` | `[]` | | authoritative list of tenant-owned tables |
| `enforcement.required_by_default` | `true` | | `BelongsToTenant` fails closed with no tenant |
| `enforcement.hide_existence` | `false` | | collapse the membership 403 → 404 |
| `enforcement.guard.dev` | `'throw'` | | dev/test guard action |
| `enforcement.guard.prod` | `'metric'` | | prod guard action — `metric` \| `log` \| `off` |
| `bypass_permissions` | `['tenancy.access_any','tenancy.manage']` | | permissions that satisfy `forAnyTenant` |
| `membership.roles` | `['owner','admin','member','viewer']` | | allowed membership roles |
