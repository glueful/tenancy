<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Bridge;

use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry as TenantTableRegistryContract;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;

/** Contract-facing registration channel into the authoritative backstop set. */
final class ContractTableRegistry implements TenantTableRegistryContract
{
    /** @param list<string> $tables */
    public function register(array $tables): void
    {
        foreach ($tables as $table) {
            if (!is_string($table) || $table === '') {
                throw new \InvalidArgumentException('Tenant table names must be non-empty strings.');
            }

            TenantTableRegistry::register($table);
        }
    }
}
