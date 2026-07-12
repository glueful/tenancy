<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Events\EventService;
use Glueful\Events\Contracts\BaseEvent;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;
use Glueful\Extensions\Tenancy\Events\HostReleased;
use Glueful\Extensions\Tenancy\Events\TenantDeleted;
use Glueful\Extensions\Tenancy\Events\TenantRestored;
use Glueful\Extensions\Tenancy\Exceptions\FinalWorkspaceException;
use Glueful\Extensions\Tenancy\Exceptions\InvalidHostException;
use Glueful\Extensions\Tenancy\Exceptions\RequiredHostOwnedException;
use Glueful\Extensions\Tenancy\Exceptions\TenantLifecycleException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Models\TenantMembership;
use Glueful\Extensions\Tenancy\Membership\AdvisoryMembershipRoleLock;
use Glueful\Extensions\Tenancy\Membership\ConfigRoleAuthority;
use Glueful\Extensions\Tenancy\Membership\MembershipRoleAuthority;
use Glueful\Extensions\Tenancy\Membership\MembershipRoleConflictException;
use Glueful\Extensions\Tenancy\Membership\MembershipRoleLock;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;
use Glueful\Helpers\Utils;

final class ContractTenantAdministration implements TenantAdministration
{
    private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public function __construct(
        private readonly ReleasedHostRepository $cooldown = new ReleasedHostRepository(),
        private readonly MembershipRoleAuthority $roleAuthority = new ConfigRoleAuthority(),
        private readonly MembershipRoleLock $roleLock = new AdvisoryMembershipRoleLock(),
    ) {
    }

    public function create(
        ApplicationContext $c,
        string $slug,
        string $name,
        string $ownerUserUuid
    ): string {
        $slug = strtolower(trim($slug));
        $name = trim($name);
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || $name === '') {
            throw new \InvalidArgumentException('Tenant slug or name is invalid.');
        }
        $this->assertSlugAvailable($c, $slug);

        $tenantUuid = Utils::generateNanoID(12);
        db($c)->transaction(function () use ($c, $tenantUuid, $slug, $name, $ownerUserUuid): void {
            Tenant::create($c, [
                'uuid' => $tenantUuid,
                'slug' => $slug,
                'name' => $name,
            ]);
            db($c)->table('tenants')->where('uuid', $tenantUuid)->update(['status' => 'provisioning']);

            $membership = TenantMembership::create($c, [
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $tenantUuid,
                'user_uuid' => $ownerUserUuid,
            ]);
            db($c)->table('tenant_memberships')->where('uuid', $membership->uuid)->update([
                'role' => 'owner',
                'status' => 'active',
            ]);
        });

        return $tenantUuid;
    }

    public function suspend(ApplicationContext $c, string $tenantUuid): void
    {
        $this->transition($c, $tenantUuid, 'active', 'suspended', 'suspend requires active state');
    }

    public function reactivate(ApplicationContext $c, string $tenantUuid): void
    {
        $this->transition($c, $tenantUuid, 'suspended', 'active', 'reactivate requires suspended state');
    }

    public function markActive(ApplicationContext $c, string $tenantUuid): void
    {
        $this->transition($c, $tenantUuid, 'provisioning', 'active', 'markActive requires provisioning state');
    }

    public function listTenants(ApplicationContext $c, ?string $status = null): array
    {
        $allowed = ['provisioning', 'active', 'suspended', 'deleted', 'purging'];
        if ($status !== null && !in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Unknown tenant status.');
        }
        $sql = 'SELECT uuid,slug,name,status,deleted_at,deleted_from_status,purge_after '
            . 'FROM tenants' . ($status === null ? '' : ' WHERE status=?')
            . ' ORDER BY created_at ASC,uuid ASC';
        $stmt = db($c)->getPDO()->prepare($sql);
        $stmt->execute($status === null ? [] : [$status]);

        return array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function getTenant(ApplicationContext $c, string $tenantUuid): ?array
    {
        $tenant = Tenant::query($c)->where('uuid', $tenantUuid)->first();

        return $tenant === null ? null : $this->tenantProjection($tenant);
    }

    public function getTenantLifecycle(ApplicationContext $c, string $tenantUuid): ?array
    {
        $stmt = db($c)->getPDO()->prepare(
            'SELECT uuid,slug,name,status,deleted_at,deleted_from_status,purge_after '
            . 'FROM tenants WHERE uuid=?'
        );
        $stmt->execute([$tenantUuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function deleteTenant(ApplicationContext $c, string $tenantUuid): void
    {
        db($c)->transaction(function () use ($c, $tenantUuid): void {
            $this->assertNotFinalWorkspace($c, $tenantUuid);
            $prior = $this->lockTenantStatus($c, $tenantUuid);
            if (!in_array($prior, ['active', 'suspended'], true)) {
                throw new TenantLifecycleException('deleteTenant requires an active or suspended tenant.');
            }
            $this->assertNotRequiredHostOwner($c, $tenantUuid);
            $days = (int) config($c, 'tenancy.tenants.trash_retention_days', 30);
            $now = gmdate('Y-m-d H:i:s');
            $purgeAfter = gmdate('Y-m-d H:i:s', time() + ($days * 86400));
            $changed = db($c)->table('tenants')->where('uuid', $tenantUuid)
                ->where('status', $prior)->update([
                    'status' => 'deleted',
                    'deleted_at' => $now,
                    'deleted_from_status' => $prior,
                    'purge_after' => $purgeAfter,
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) {
                throw new TenantLifecycleException('deleteTenant lost the status race.');
            }
            $this->afterCommit($c, new TenantDeleted($tenantUuid, $prior, $purgeAfter));
        });
    }

    public function restoreTenant(ApplicationContext $c, string $tenantUuid): void
    {
        db($c)->transaction(function () use ($c, $tenantUuid): void {
            $row = $this->lockTenantLifecycle($c, $tenantUuid);
            $restoreTo = (string) ($row['deleted_from_status'] ?? '');
            if (
                ($row['status'] ?? null) !== 'deleted'
                || !in_array($restoreTo, ['active', 'suspended'], true)
                || !is_string($row['purge_after'] ?? null)
                || (string) $row['purge_after'] < gmdate('Y-m-d H:i:s')
            ) {
                throw new TenantLifecycleException('Tenant is not restorable.');
            }
            $changed = db($c)->table('tenants')->where('uuid', $tenantUuid)
                ->where('status', 'deleted')->update([
                    'status' => $restoreTo,
                    'deleted_at' => null,
                    'deleted_from_status' => null,
                    'purge_after' => null,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            if ($changed !== 1) {
                throw new TenantLifecycleException('restoreTenant lost the status race.');
            }
            $this->afterCommit($c, new TenantRestored($tenantUuid, $restoreTo));
        });
    }

    public function beginPurge(ApplicationContext $c, string $tenantUuid): void
    {
        db($c)->transaction(function () use ($c, $tenantUuid): void {
            if ($this->lockTenantStatus($c, $tenantUuid) !== 'deleted') {
                throw new TenantLifecycleException('beginPurge requires a deleted tenant.');
            }
            $changed = db($c)->table('tenants')->where('uuid', $tenantUuid)
                ->where('status', 'deleted')->update([
                    'status' => 'purging', 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            if ($changed !== 1) {
                throw new TenantLifecycleException('beginPurge lost the status race.');
            }
        });
    }

    public function purgeTenantRecord(ApplicationContext $c, string $tenantUuid): void
    {
        db($c)->transaction(function () use ($c, $tenantUuid): void {
            if ($this->lockTenantStatus($c, $tenantUuid) !== 'purging') {
                throw new TenantLifecycleException('purgeTenantRecord requires a purging tenant.');
            }
            $stmt = db($c)->getPDO()->prepare(
                'SELECT host FROM tenant_domains WHERE tenant_uuid=? ORDER BY host'
            );
            $stmt->execute([$tenantUuid]);
            $hosts = array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
            $this->cooldown->lockHosts($c, $hosts);
            $days = (int) config($c, 'tenancy.domains.release_cooldown_days', 30);
            $retainedUntil = gmdate('Y-m-d H:i:s', time() + ($days * 86400));
            foreach ($hosts as $host) {
                $this->cooldown->upsertTombstone($c, $host, $tenantUuid, $retainedUntil);
            }
            db($c)->table('tenant_domains')->where('tenant_uuid', $tenantUuid)->forceDelete();
            db($c)->table('tenant_memberships')->where('tenant_uuid', $tenantUuid)->forceDelete();
            $deleted = db($c)->table('tenants')->where('uuid', $tenantUuid)
                ->where('status', 'purging')->forceDelete();
            if ($deleted !== 1) {
                throw new TenantLifecycleException('purgeTenantRecord lost the status race.');
            }
            foreach ($hosts as $host) {
                $this->afterCommit($c, new HostReleased($host, $tenantUuid, $retainedUntil));
            }
        });
    }

    public function listTenantsForUser(ApplicationContext $c, string $userUuid): array
    {
        $rows = db($c)->getPDO()->prepare(
            "SELECT t.uuid,t.slug,t.name,t.status FROM tenants t "
            . "INNER JOIN tenant_memberships m ON m.tenant_uuid=t.uuid "
            . "WHERE m.user_uuid=? AND m.status='active' AND t.status='active' AND t.deleted_at IS NULL "
            . 'ORDER BY t.created_at ASC,t.uuid ASC'
        );
        $rows->execute([$userUuid]);

        return array_values($rows->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function listMembers(ApplicationContext $c, string $tenantUuid): array
    {
        $result = [];
        $members = TenantMembership::query($c)
            ->where('tenant_uuid', $tenantUuid)
            ->orderBy('created_at', 'asc')
            ->orderBy('uuid', 'asc')
            ->get();
        foreach ($members as $member) {
            $result[] = [
                'uuid' => $member->uuid,
                'user_uuid' => $member->user_uuid,
                'role' => $member->role,
                'status' => $member->status,
            ];
        }

        return $result;
    }

    public function addMember(
        ApplicationContext $c,
        string $tenantUuid,
        string $userUuid,
        string $role
    ): void {
        $attempt = 0;
        while (true) {
            try {
                db($c)->transaction(function () use ($c, $tenantUuid, $userUuid, $role): void {
                    $snapshot = $this->membership($c, $tenantUuid, $userUuid);
                    $roles = $snapshot === null ? [$role] : [$role, (string) $snapshot->role];
                    $this->roleLock->lockMany($c, $tenantUuid, $roles);
                    $this->assertAssignable($c, $tenantUuid, $role);
                    $current = $this->membership($c, $tenantUuid, $userUuid);
                    $this->assertRoleStillLocked($current, $roles);
                    if ($current === null) {
                        $current = TenantMembership::create($c, [
                            'uuid' => Utils::generateNanoID(12),
                            'tenant_uuid' => $tenantUuid,
                            'user_uuid' => $userUuid,
                        ]);
                    }
                    db($c)->table('tenant_memberships')->where('uuid', $current->uuid)->update([
                        'role' => $role,
                        'status' => 'active',
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                });
                return;
            } catch (\Throwable $exception) {
                if (!$this->isUniqueViolation($exception) || $attempt++ >= 1) {
                    if ($this->isUniqueViolation($exception)) {
                        throw new MembershipRoleConflictException(
                            'Membership changed concurrently; retry.',
                            0,
                            $exception,
                        );
                    }
                    throw $exception;
                }
            }
        }
    }

    public function removeMember(ApplicationContext $c, string $tenantUuid, string $userUuid): void
    {
        db($c)->transaction(function () use ($c, $tenantUuid, $userUuid): void {
            $this->assertNotFinalOwner($c, $tenantUuid, $userUuid);
            db($c)->table('tenant_memberships')
                ->where('tenant_uuid', $tenantUuid)
                ->where('user_uuid', $userUuid)
                ->delete();
        });
    }

    public function setMemberRole(
        ApplicationContext $c,
        string $tenantUuid,
        string $userUuid,
        string $role
    ): void {
        db($c)->transaction(function () use ($c, $tenantUuid, $userUuid, $role): void {
            $snapshot = $this->membership($c, $tenantUuid, $userUuid);
            $roles = $snapshot === null ? [$role] : [$role, (string) $snapshot->role];
            $this->roleLock->lockMany($c, $tenantUuid, $roles);
            $this->assertAssignable($c, $tenantUuid, $role);
            $current = $this->membership($c, $tenantUuid, $userUuid);
            $this->assertRoleStillLocked($current, $roles);
            if ($current === null) {
                throw new \RuntimeException('Tenant membership was not found.');
            }
            if ($role !== 'owner') {
                $this->assertNotFinalOwner($c, $tenantUuid, $userUuid);
            }
            $changed = db($c)->table('tenant_memberships')
                ->where('tenant_uuid', $tenantUuid)
                ->where('user_uuid', $userUuid)
                ->update(['role' => $role, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            if ($changed === 0) {
                throw new \RuntimeException('Tenant membership was not found.');
            }
        });
    }

    private function transition(
        ApplicationContext $c,
        string $tenantUuid,
        string $from,
        string $to,
        string $message
    ): void {
        $changed = db($c)->table('tenants')
            ->where('uuid', $tenantUuid)
            ->where('status', $from)
            ->update(['status' => $to, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        if ($changed === 0) {
            throw new \RuntimeException($message);
        }
    }

    private function lockTenantStatus(ApplicationContext $c, string $tenantUuid): ?string
    {
        $row = $this->lockTenantLifecycle($c, $tenantUuid);

        return is_string($row['status'] ?? null) ? $row['status'] : null;
    }

    /** @return array<string,mixed> */
    private function lockTenantLifecycle(ApplicationContext $c, string $tenantUuid): array
    {
        $sql = 'SELECT uuid,status,deleted_at,deleted_from_status,purge_after '
            . 'FROM tenants WHERE uuid=?';
        if (db($c)->getDriverName() !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = db($c)->getPDO()->prepare($sql);
        $stmt->execute([$tenantUuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? [] : $row;
    }

    private function assertNotFinalWorkspace(ApplicationContext $c, string $tenantUuid): void
    {
        $sql = "SELECT uuid FROM tenants WHERE status IN ('provisioning','active','suspended') "
            . 'AND deleted_at IS NULL ORDER BY uuid';
        if (db($c)->getDriverName() !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $rows = db($c)->getPDO()->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
        if (count($rows) <= 1 && in_array($tenantUuid, $rows, true)) {
            throw new FinalWorkspaceException('Cannot delete the final workspace.');
        }
    }

    private function assertNotRequiredHostOwner(ApplicationContext $c, string $tenantUuid): void
    {
        $configured = config($c, 'tenancy.public_origin.default_hosts', []);
        $required = [];
        foreach (is_array($configured) ? $configured : [] as $host) {
            if (is_string($host) && trim($host) !== '') {
                $required[HostNormalizer::normalize($host)] = true;
            }
        }
        if ($required === []) {
            return;
        }
        $stmt = db($c)->getPDO()->prepare('SELECT host FROM tenant_domains WHERE tenant_uuid=?');
        $stmt->execute([$tenantUuid]);
        $owned = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $host) {
            $host = HostNormalizer::normalize((string) $host);
            if (isset($required[$host])) {
                $owned[] = $host;
            }
        }
        if ($owned !== []) {
            throw new RequiredHostOwnedException($owned);
        }
    }

    private function afterCommit(ApplicationContext $c, BaseEvent $event): void
    {
        db($c)->afterCommit(static function () use ($c, $event): void {
            $container = $c->getContainer();
            if ($container->has(EventService::class)) {
                $container->get(EventService::class)->dispatch($event);
            }
        });
    }

    private function assertSlugAvailable(ApplicationContext $c, string $slug): void
    {
        /** @var array<string,mixed> $origin */
        $origin = (array) config($c, 'tenancy.public_origin', []);
        $base = $origin['base_domain'] ?? null;
        if (is_string($base) && $base !== '') {
            HostNormalizer::validateForRegistration(
                HostNormalizer::normalize($slug . '.' . $base),
                $origin
            );
            return;
        }
        $reserved = $origin['reserved_labels'] ?? [];
        if (is_array($reserved) && in_array($slug, $reserved, true)) {
            throw new InvalidHostException('Tenant slug uses a reserved platform label.');
        }
    }

    private function assertAssignable(ApplicationContext $c, string $tenantUuid, string $role): void
    {
        if (!$this->roleAuthority->isAssignable($c, $tenantUuid, $role)) {
            throw new \InvalidArgumentException('Unknown tenant membership role.');
        }
    }

    private function membership(
        ApplicationContext $c,
        string $tenantUuid,
        string $userUuid,
    ): ?TenantMembership {
        return TenantMembership::query($c)
            ->where('tenant_uuid', $tenantUuid)
            ->where('user_uuid', $userUuid)
            ->first();
    }

    /** @param list<string> $lockedRoles */
    private function assertRoleStillLocked(?TenantMembership $membership, array $lockedRoles): void
    {
        if ($membership !== null && !in_array((string) $membership->role, $lockedRoles, true)) {
            throw new MembershipRoleConflictException('Membership role changed concurrently; retry.');
        }
    }

    private function isUniqueViolation(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ((string) $current->getCode() === '23505') {
                return true;
            }
        }
        return false;
    }

    private function assertNotFinalOwner(
        ApplicationContext $c,
        string $tenantUuid,
        string $userUuid
    ): void {
        $sql = "SELECT user_uuid FROM tenant_memberships WHERE tenant_uuid=? "
            . "AND role='owner' AND status='active'";
        if (db($c)->getDriverName() !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $statement = db($c)->getPDO()->prepare($sql);
        $statement->execute([$tenantUuid]);
        $owners = $statement->fetchAll(\PDO::FETCH_COLUMN);
        if (count($owners) === 1 && $owners[0] === $userUuid) {
            throw new \DomainException('Cannot remove or demote the final active owner');
        }
    }

    /** @return array{uuid:string,slug:string,name:string,status:string} */
    private function tenantProjection(Tenant $tenant): array
    {
        return [
            'uuid' => $tenant->uuid,
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'status' => $tenant->status,
        ];
    }
}
