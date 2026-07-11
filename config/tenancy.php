<?php

return [
    'enabled' => true,
    'resolvers' => ['domain', 'subdomain', 'path', 'header', 'query', 'jwt', 'active_session'],
    'public_origin' => [
        'scheme' => 'https',
        'base_domain' => env('TENANCY_BASE_DOMAIN'),
        'default_hosts' => [],
        'reserved_labels' => ['www', 'api', 'admin'],
    ],
    'profiles' => [
        'public' => [
            'resolvers' => ['domain', 'subdomain'],
            'require_membership' => false,
            'require_authenticated' => false,
            'uuid_only' => false,
            'conflict' => 'ignore',
        ],
        'admin' => [
            'resolvers' => ['header', 'jwt'],
            'require_membership' => true,
            'require_authenticated' => true,
            'uuid_only' => true,
            'conflict' => 'reject',
        ],
    ],
    'path'      => ['segment' => 't'],            // /t/{tenant}/...
    'header'    => ['name' => 'X-Tenant-Id'],
    'query'     => ['name' => 'tenant_id'],
    'jwt'       => ['claim' => 'tenant_id'],
    'tables'    => [],                            // AUTHORITATIVE list of tenant-owned tables (every BelongsToTenant model's table)
    'enforcement' => [
        'required_by_default' => true,            // BelongsToTenant fails closed
        'require_authenticated' => true,          // tenant candidates require auth before membership/bypass checks
        'hide_existence' => false,                // when true the tenant middleware collapses 403 → 404 (never leak membership)
        'guard' => [
            'dev'  => 'throw',                    // dev/test: pre-execution interceptor throws
            'prod' => 'metric',                   // prod: post-execution monitor — metric | log | off
        ],
    ],
    'bypass_permissions' => ['tenancy.access_any', 'tenancy.manage'],
    'membership' => ['roles' => ['owner', 'admin', 'member', 'viewer']],
    'domains' => [
        'release_cooldown_days' => (int) env('TENANCY_HOST_COOLDOWN_DAYS', 30),
        'reverification' => [
            'enabled' => (bool) env('TENANCY_REVERIFICATION_ENABLED', true),
            'recheck_interval_hours' => (int) env('TENANCY_REVERIFICATION_INTERVAL_HOURS', 12),
            'revoked_recheck_interval_hours' => (int) env(
                'TENANCY_REVERIFICATION_REVOKED_INTERVAL_HOURS',
                24
            ),
            'failure_threshold' => (int) env('TENANCY_REVERIFICATION_FAILURE_THRESHOLD', 3),
            'grace_hours' => (int) env('TENANCY_REVERIFICATION_GRACE_HOURS', 24),
            'batch_size' => (int) env('TENANCY_REVERIFICATION_BATCH_SIZE', 100),
        ],
    ],
    'tenants' => [
        'trash_retention_days' => (int) env('TENANCY_TRASH_RETENTION_DAYS', 30),
        'auto_purge_enabled' => (bool) env('TENANCY_AUTO_PURGE_ENABLED', false),
    ],
];
