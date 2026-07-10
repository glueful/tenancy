<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantRequestMiddleware as TenantRequestMiddlewareContract;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantAccessDeniedException;
use Glueful\Extensions\Tenancy\Exceptions\TenantNotFoundException;
use Glueful\Extensions\Tenancy\Resolution\TenantResolutionPipeline;
use Glueful\Extensions\Tenancy\Resolution\ResolutionProfile;
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
final class TenantMiddleware implements RouteMiddleware, TenantRequestMiddlewareContract
{
    public function __construct(
        private readonly TenantResolutionPipeline $pipeline,
        private readonly ApplicationContext $context,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $tokens = [];
        foreach ($params as $param) {
            foreach (explode(':', (string) $param) as $token) {
                $tokens[] = trim($token);
            }
        }

        $soft = in_array('soft', $tokens, true);
        $required = !in_array('optional', $tokens, true) && !$soft;
        $profileName = null;
        foreach ($tokens as $token) {
            if ($token !== '' && $token !== 'soft' && $token !== 'optional') {
                $profileName = $token;
                break;
            }
        }

        $profile = null;
        if ($profileName !== null) {
            $container = $this->context->getContainer();
            $ready = $container->has(FullTenantResolutionReadiness::class)
                && $container->get(FullTenantResolutionReadiness::class)->isReady($this->context);
            if (!$ready) {
                return $next($request);
            }
            $profile = ResolutionProfile::fromConfig($this->context, $profileName);
        }

        // Point the process-level holder at this request's context so DB-layer hooks
        // (auto-injection / the query guard) can read request-scoped tenancy state.
        CurrentContext::set($this->context);

        // Stash the authenticated user uuid on request-state so the Tenancy bypass facade can
        // read the current user context-only (it has no Request). Mirrors the value the
        // resolution pipeline reads from the 'auth.user.uuid' request attribute.
        $userUuid = $request->attributes->get('auth.user.uuid');
        $this->context->setRequestState(
            'tenancy.user_uuid',
            is_string($userUuid) ? $userUuid : null
        );

        try {
            $this->pipeline->resolve($request, $this->context, $required, $profile);

            return $next($request);
        } catch (TenantNotFoundException) {
            if ($soft) {
                (new TenantContext($this->context))->clear();
                return $next($request);
            }
            return Response::error('Tenant not found', Response::HTTP_NOT_FOUND);
        } catch (TenantAccessDeniedException) {
            if ($soft) {
                (new TenantContext($this->context))->clear();
                return $next($request);
            }
            if ((bool) config($this->context, 'tenancy.enforcement.hide_existence', false) === true) {
                // Existence-hiding: a non-member sees the same 404 as a stranger.
                return Response::error('Tenant not found', Response::HTTP_NOT_FOUND);
            }

            return Response::error('Access to this tenant is denied', Response::HTTP_FORBIDDEN);
        } finally {
            // Win or lose, never leak tenancy request-state past this request.
            (new TenantContext($this->context))->clear();
            $this->context->setRequestState('tenancy.user_uuid', null);
            CurrentContext::clear();
        }
    }
}
