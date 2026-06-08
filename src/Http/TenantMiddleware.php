<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantAccessDeniedException;
use Glueful\Extensions\Tenancy\Exceptions\TenantNotFoundException;
use Glueful\Extensions\Tenancy\Resolution\TenantResolutionPipeline;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * The `tenant` route middleware — the request-time entry point of the extension.
 *
 * It runs the validated {@see TenantResolutionPipeline} (the security boundary), then:
 *
 *   - on success, delegates to the next handler with the tenant context populated;
 *   - on {@see TenantNotFoundException} returns a 404 (unknown OR inactive tenant — the
 *     pipeline never distinguishes the two, so existence is not leaked);
 *   - on {@see TenantAccessDeniedException} returns a 403 (authenticated non-member),
 *     collapsed to 404 when `tenancy.enforcement.hide_existence` is on so that even
 *     membership cannot be probed;
 *   - ALWAYS clears the request-scoped tenancy state afterwards (the `finally`), so state
 *     never leaks into a later handler/request even on the success path.
 *
 * Tenancy must run AFTER authentication, because the pipeline reads `auth.user.uuid` from
 * the request to decide membership — register `tenant` after the `auth` middleware.
 *
 * Routes opt out of the "a tenant is required" default with `->middleware(['tenant:optional'])`
 * (i.e. passing the literal 'optional' parameter); central/optional routes then tolerate a
 * missing tenant instead of 404ing.
 */
final class TenantMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly TenantResolutionPipeline $pipeline,
        private readonly ApplicationContext $context,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $required = !in_array('optional', $params, true);

        try {
            $this->pipeline->resolve($request, $this->context, $required);

            return $next($request);
        } catch (TenantNotFoundException) {
            return Response::error('Tenant not found', Response::HTTP_NOT_FOUND);
        } catch (TenantAccessDeniedException) {
            if ((bool) config($this->context, 'tenancy.enforcement.hide_existence', false) === true) {
                // Existence-hiding: a non-member sees the same 404 as a stranger.
                return Response::error('Tenant not found', Response::HTTP_NOT_FOUND);
            }

            return Response::error('Access to this tenant is denied', Response::HTTP_FORBIDDEN);
        } finally {
            // Win or lose, never leak tenancy request-state past this request.
            (new TenantContext($this->context))->clear();
        }
    }
}
