<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Unit\Schema;

use Glueful\Extensions\Tenancy\Schema\TenancySchemaVerifier;
use PHPUnit\Framework\TestCase;

final class SchemaManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);
        return $composer['extra']['glueful'];
    }

    public function testDeclaresExactlyOneDefaultDependentOnEnableDescriptor(): void
    {
        $migrations = $this->manifest()['migrations'];
        self::assertCount(1, $migrations);
        self::assertSame('default', $migrations[0]['id']);
        self::assertSame('migrations', $migrations[0]['path']);
        self::assertSame('platform', $migrations[0]['priority']);
        self::assertSame('core', $migrations[0]['mode']);
        self::assertSame('>=1.79.0', $this->manifest()['requires']['glueful']);
        self::assertSame([], $this->manifest()['requires']['extensions']);
    }

    public function testVerifierClassConformsToTheManifestContract(): void
    {
        $class = $this->manifest()['migrations'][0]['verifier'];
        self::assertTrue(class_exists($class));
        self::assertTrue(is_subclass_of($class, \Glueful\Extensions\Schema\StructuralVerifierInterface::class));
        $constructor = (new \ReflectionClass($class))->getConstructor();
        self::assertTrue($constructor === null || $constructor->getNumberOfRequiredParameters() === 0);
        self::assertSame('glueful/tenancy', (new $class())->source());
    }

    public function testVerifierCoversEveryRecursivelyDiscoveredMigration(): void
    {
        $mapped = (new TenancySchemaVerifier())->migrationBasenames();
        $root = dirname(__DIR__, 3) . '/migrations';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        $shipped = [];
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $shipped[] = $file->getBasename();
            }
        }
        sort($shipped);
        sort($mapped);
        self::assertSame($shipped, $mapped, 'every migration file needs a verifier proof');
    }

    public function testResolvesWhenEnabledAloneAtItsDeclaredFloor(): void
    {
        $g = $this->manifest();
        $candidates = ['glueful/tenancy' => new \Glueful\Extensions\ExtensionCandidate(
            name: 'glueful/tenancy',
            provider: $g['provider'],
            requiresGlueful: $g['requires']['glueful'],
            requiresExtensions: $g['requires']['extensions'],
        )];
        $result = (new \Glueful\Extensions\ExtensionResolver())
            ->resolve($candidates, [$g['provider']], '1.79.0');
        self::assertSame([], $result->errors, 'tenancy must resolve enabled-alone');
    }

    public function testNeitherProviderRegistersTheManifestOwnedPath(): void
    {
        // The manifest provider is TenancyServiceProvider, but the always-loaded
        // TenancyControlPlaneProvider historically owned the loadMigrationsFrom() call: the
        // manifest is the sole inventory, so BOTH must be clean.
        foreach (
            [
            $this->manifest()['provider'],
            \Glueful\Extensions\Tenancy\TenancyControlPlaneProvider::class,
            ] as $provider
        ) {
            $file = (new \ReflectionClass($provider))->getFileName();
            self::assertIsString($file);
            self::assertStringNotContainsString(
                'loadMigrationsFrom(',
                (string) file_get_contents($file),
                "{$provider} must not register the manifest-owned path"
            );
        }
    }
}
