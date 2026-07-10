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
];
