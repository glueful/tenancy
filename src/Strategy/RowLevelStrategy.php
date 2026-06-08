<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Strategy;

final class RowLevelStrategy implements TenancyStrategyInterface
{
    public function name(): string
    {
        return 'row-level';
    }

    public function scopesViaColumn(): bool
    {
        return true;
    }
}
