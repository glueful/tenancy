<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;

interface TenantResolverInterface
{
    /**
     * Resolve a candidate tenant identifier from the request.
     *
     * @return string|null candidate tenant uuid OR slug (untrusted — validated later)
     */
    public function resolve(Request $request, ApplicationContext $context): ?string;
}
