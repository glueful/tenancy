<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Bridge;

use Glueful\Extensions\Contracts\Tenancy\TenantEnforcementProbe;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;

final class ContractEnforcementProbe implements TenantEnforcementProbe
{
    public function isRegistered(string $table): bool
    {
        return TenantTableRegistry::isTenantOwned($table);
    }

    /** @return list<string> */
    public function registeredTables(): array
    {
        return TenantTableRegistry::all();
    }
}
