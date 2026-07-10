<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution;

use Glueful\Extensions\Tenancy\Exceptions\InvalidHostException;

final class HostNormalizer
{
    private const HOST_PATTERN = '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/';

    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');

        if (preg_match('/^(.+):([0-9]{1,5})$/', $host, $matches) === 1) {
            $host = $matches[1];
        }

        if ($host === '' || str_contains($host, '*') || str_starts_with($host, '[')) {
            throw new InvalidHostException('Host is empty or uses an unsupported address form.');
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii)) {
                throw new InvalidHostException('Host contains an invalid internationalized label.');
            }
            $host = strtolower($ascii);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidHostException('IP literals cannot be registered as tenant hosts.');
        }
        if (preg_match(self::HOST_PATTERN, $host) !== 1 || strlen($host) > 253) {
            throw new InvalidHostException('Host is malformed.');
        }

        return $host;
    }

    /** @param array<string,mixed> $publicOrigin */
    public static function validateForRegistration(
        string $normalized,
        array $publicOrigin,
        bool $allowBaseDomain = false
    ): void {
        $base = $publicOrigin['base_domain'] ?? null;
        if (!is_string($base) || trim($base) === '') {
            return;
        }
        $base = self::normalize($base);

        if (!$allowBaseDomain && $normalized === $base) {
            throw new InvalidHostException('The base domain is reserved for platform routing.');
        }

        $reserved = $publicOrigin['reserved_labels'] ?? [];
        if (!is_array($reserved)) {
            return;
        }
        foreach ($reserved as $label) {
            if (is_string($label) && $normalized === strtolower($label) . '.' . $base) {
                throw new InvalidHostException('Host uses a reserved platform label.');
            }
        }
    }
}
