<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests;

use Glueful\Extensions\Tenancy\Exceptions\InvalidHostException;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostNormalizerTest extends TestCase
{
    #[DataProvider('normalizationCases')]
    public function testNormalizesValidHosts(string $input, string $expected): void
    {
        self::assertSame($expected, HostNormalizer::normalize($input));
    }

    /** @return iterable<string,array{string,string}> */
    public static function normalizationCases(): iterable
    {
        yield 'case and dot' => ['ACME.Example.COM.', 'acme.example.com'];
        yield 'port' => ['foo.test:8080', 'foo.test'];
        yield 'idn' => ['münchen.de', 'xn--mnchen-3ya.de'];
    }

    #[DataProvider('invalidHosts')]
    public function testRejectsInvalidHosts(string $host): void
    {
        $this->expectException(InvalidHostException::class);
        HostNormalizer::normalize($host);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidHosts(): iterable
    {
        yield 'ipv4' => ['192.168.0.1'];
        yield 'ipv6' => ['[::1]'];
        yield 'wildcard' => ['*.example.com'];
        yield 'bad characters' => ['bad_host!.com'];
        yield 'empty label' => ['bad..example.com'];
    }

    public function testRegistrationProtectsBaseAndReservedHosts(): void
    {
        $origin = [
            'base_domain' => 'example.com',
            'reserved_labels' => ['www', 'api'],
        ];

        foreach (['example.com', 'www.example.com'] as $host) {
            try {
                HostNormalizer::validateForRegistration($host, $origin);
                self::fail("Expected $host to be rejected");
            } catch (InvalidHostException) {
                self::addToAssertionCount(1);
            }
        }

        HostNormalizer::validateForRegistration('example.com', $origin, true);
        self::addToAssertionCount(1);
    }
}
