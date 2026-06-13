<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Scheduling;

/**
 * Summary of a ForEachTenant fan-out run.
 */
final class ForEachTenantResult
{
    /**
     * @param array<string,\Throwable> $errors
     */
    public function __construct(
        public readonly int $total,
        public readonly int $succeeded,
        public readonly int $failed,
        public readonly array $errors,
    ) {
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
