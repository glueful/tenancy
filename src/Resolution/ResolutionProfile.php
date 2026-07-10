<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution;

use Glueful\Bootstrap\ApplicationContext;

/** Immutable request-resolution policy selected by route middleware. */
final readonly class ResolutionProfile
{
    /** @param list<string> $resolvers */
    public function __construct(
        public string $name,
        public array $resolvers,
        public bool $requireMembership,
        public bool $requireAuthenticated,
        public bool $uuidOnly,
        public bool $conflictReject,
    ) {
    }

    public static function fromConfig(ApplicationContext $context, string $name): self
    {
        $config = config($context, 'tenancy.profiles.' . $name);
        if (!is_array($config)) {
            throw new \InvalidArgumentException("Unknown tenant resolution profile: {$name}");
        }

        $resolvers = $config['resolvers'] ?? [];
        if (!is_array($resolvers)) {
            throw new \InvalidArgumentException("Invalid resolver list for tenant profile: {$name}");
        }

        return new self(
            $name,
            array_values(array_filter($resolvers, 'is_string')),
            (bool) ($config['require_membership'] ?? true),
            (bool) ($config['require_authenticated'] ?? true),
            (bool) ($config['uuid_only'] ?? false),
            ($config['conflict'] ?? 'ignore') === 'reject',
        );
    }
}
