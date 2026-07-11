<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution;

/** Structured TXT lookup result that distinguishes DNS failure from an empty answer. */
final class DnsTxtResult
{
    /** @param list<string> $records */
    public function __construct(
        public readonly string $status,
        public readonly array $records = [],
    ) {
        if (!in_array($status, ['success', 'error'], true)) {
            throw new \InvalidArgumentException('DNS TXT status must be success or error.');
        }
    }

    public function isError(): bool
    {
        return $this->status === 'error';
    }
}
