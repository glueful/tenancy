<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Strategy;

interface TenancyStrategyInterface
{
    public function name(): string;

    /** True when isolation is achieved via a tenant_uuid column predicate (row-level). */
    public function scopesViaColumn(): bool;
}
