<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Tenancy\Context\TenantContext;

/** Binds the shared contract over tenancy's request-scoped TenantContext. */
final class ContractTenantResolver implements CurrentTenantResolver
{
    public function tenantUuid(ApplicationContext $context): string
    {
        return (new TenantContext($context))->currentTenantUuid() ?? '';
    }
}
