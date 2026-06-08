<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution\Resolvers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\TenantResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves a tenant candidate from a leading `/<segment>/<tenant>/...` path.
 *
 * With segment `t` (default), `/t/acme/posts` yields `acme`. Returns null when
 * the path does not begin with the configured segment or has no tenant label.
 */
final class PathResolver implements TenantResolverInterface
{
    public function resolve(Request $request, ApplicationContext $context): ?string
    {
        $segment = (string) config($context, 'tenancy.path.segment', 't');

        $path = trim($request->getPathInfo(), '/');

        if ($path === '') {
            return null;
        }

        $parts = explode('/', $path);

        if ($parts[0] !== $segment) {
            return null;
        }

        return isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
    }
}
