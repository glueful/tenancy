<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Exceptions;

final class RequiredHostOwnedException extends \DomainException
{
    /** @param list<string> $hosts */
    public function __construct(public readonly array $hosts)
    {
        parent::__construct('Workspace owns required default host(s): ' . implode(', ', $hosts));
    }
}
