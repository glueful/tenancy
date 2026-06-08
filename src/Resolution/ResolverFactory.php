<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\ActiveSessionResolver;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\HeaderResolver;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\JwtClaimResolver;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\PathResolver;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\QueryResolver;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\SubdomainResolver;

/**
 * Builds an ordered {@see ResolverChain} from the configured resolver name-list
 * (`config('tenancy.resolvers')`).
 *
 * The order in config IS the precedence order — the chain returns the first non-null
 * candidate. Names map to stateless resolver classes; unknown names are skipped (friendlier
 * than throwing, and lets an app trim the list without removing a class). This is the single
 * place name→class mapping lives, so the container can build the chain via a factory.
 */
final class ResolverFactory
{
    /**
     * Default order, mirroring config/tenancy.php, used when no list is configured.
     *
     * @var list<string>
     */
    private const DEFAULT_ORDER = ['subdomain', 'path', 'header', 'query', 'jwt', 'active_session'];

    /**
     * Name → resolver class. Resolvers are stateless and constructor-arg-free.
     *
     * @var array<string, class-string<TenantResolverInterface>>
     */
    private const MAP = [
        'subdomain'      => SubdomainResolver::class,
        'path'           => PathResolver::class,
        'header'         => HeaderResolver::class,
        'query'          => QueryResolver::class,
        'jwt'            => JwtClaimResolver::class,
        'active_session' => ActiveSessionResolver::class,
    ];

    public static function chain(ApplicationContext $context): ResolverChain
    {
        /** @var list<string> $names */
        $names = (array) config($context, 'tenancy.resolvers', self::DEFAULT_ORDER);

        $resolvers = [];
        foreach ($names as $name) {
            $class = self::MAP[$name] ?? null;
            if ($class === null) {
                // Unknown resolver name — skip rather than fail the whole chain.
                continue;
            }
            $resolvers[] = new $class();
        }

        return new ResolverChain($resolvers);
    }
}
