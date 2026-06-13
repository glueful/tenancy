<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Unit\Query;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantScopeViolationException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Query\TenantQueryGuard;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Unit coverage for {@see TenantQueryGuard::before()} — exercised directly, no DB.
 *
 * Dev-vs-prod is decided from the CURRENT context's environment
 * ({@see ApplicationContext::getEnvironment()}): 'testing' (the default here) is a dev
 * environment, so the guard THROWS by default. Prod cases build a context with
 * environment 'production'.
 */
final class TenantQueryGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantTableRegistry::clear();
        CurrentContext::clear();
    }

    protected function tearDown(): void
    {
        TenantTableRegistry::clear();
        CurrentContext::clear();
        parent::tearDown();
    }

    /**
     * Build a context (no container, no logger needed for dev/throw paths).
     */
    private function devContext(): ApplicationContext
    {
        return new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
    }

    private function activate(ApplicationContext $ctx): void
    {
        CurrentContext::set($ctx);
    }

    private function activateTenant(ApplicationContext $ctx, string $tenantUuid = 'tenant-a'): void
    {
        (new TenantContext($ctx))->setTenant(new Tenant([
            'uuid' => $tenantUuid,
            'slug' => 'tenant-a',
            'name' => 'Tenant A',
        ]));
        CurrentContext::set($ctx);
    }

    public function testThrowsForRegisteredTableWithoutTenantPredicate(): void
    {
        TenantTableRegistry::register('invoices');
        $this->activate($this->devContext());

        $this->expectException(TenantScopeViolationException::class);
        $this->expectExceptionMessageMatches('/invoices/');

        (new TenantQueryGuard())->before('select * from invoices', []);
    }

    public function testNoThrowWhenTenantUuidPredicatePresent(): void
    {
        TenantTableRegistry::register('invoices');
        $this->activate($this->devContext());

        (new TenantQueryGuard())->before('select * from invoices where tenant_uuid = ?', ['x']);

        $this->expectNotToPerformAssertions();
    }

    public function testNoThrowForUnregisteredTable(): void
    {
        TenantTableRegistry::register('invoices');
        $this->activate($this->devContext());

        (new TenantQueryGuard())->before('select * from widgets', []);

        $this->expectNotToPerformAssertions();
    }

    public function testNoThrowWhenBypassActive(): void
    {
        TenantTableRegistry::register('invoices');
        $ctx = $this->devContext();
        (new TenantContext($ctx))->setBypass('forAnyTenant');
        $this->activate($ctx);

        (new TenantQueryGuard())->before('select * from invoices', []);

        $this->expectNotToPerformAssertions();
    }

    public function testNoThrowWhenNoCurrentContext(): void
    {
        TenantTableRegistry::register('invoices');
        // CurrentContext intentionally left unset (e.g. migrations / boot).

        (new TenantQueryGuard())->before('select * from invoices', []);

        $this->expectNotToPerformAssertions();
    }

    public function testProdMetricModeLogsAndDoesNotThrow(): void
    {
        TenantTableRegistry::register('invoices');

        $logger = new class extends AbstractLogger {
            /** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $ctx = $this->prodContextWithLogger($logger, guardProd: 'metric');
        $this->activate($ctx);

        (new TenantQueryGuard())->before('select * from invoices', []);

        self::assertNotEmpty($logger->records, 'metric/log mode must emit a warning');
        $record = $logger->records[0];
        self::assertSame('warning', (string) $record['level']);
        self::assertStringContainsString('invoices', $record['message'] . json_encode($record['context']));
        self::assertSame('tenancy.unscoped_query', $record['context']['event'] ?? null);
    }

    public function testProdOffModeDoesNothing(): void
    {
        TenantTableRegistry::register('invoices');

        $logger = new class extends AbstractLogger {
            /** @var list<mixed> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = $message;
            }
        };

        $ctx = $this->prodContextWithLogger($logger, guardProd: 'off');
        $this->activate($ctx);

        (new TenantQueryGuard())->before('select * from invoices', []);

        self::assertEmpty($logger->records, 'off mode must not log');
    }

    public function testJoinToRegisteredTableWithoutPredicateThrows(): void
    {
        TenantTableRegistry::register('invoices');
        $this->activate($this->devContext());

        $this->expectException(TenantScopeViolationException::class);

        (new TenantQueryGuard())->before(
            'select * from customers c join invoices i on i.customer_id = c.id',
            []
        );
    }

    public function testJoinToRegisteredTableWithPredicateDoesNotThrow(): void
    {
        TenantTableRegistry::register('invoices');
        $this->activate($this->devContext());

        (new TenantQueryGuard())->before(
            'select * from customers c join invoices i on i.customer_id = c.id '
            . 'where i.tenant_uuid = ?',
            ['x']
        );

        $this->expectNotToPerformAssertions();
    }

    public function testDdlIsSkipped(): void
    {
        TenantTableRegistry::register('invoices');
        $this->activate($this->devContext());

        (new TenantQueryGuard())->before('create table invoices (id integer, tenant_uuid text)', []);

        $this->expectNotToPerformAssertions();
    }

    public function testMultiRowInsertCannotWriteForeignTenantUuidInLaterRow(): void
    {
        TenantTableRegistry::register('invoices');
        $this->activateTenant($this->devContext(), 'tenant-a');

        $this->expectException(TenantScopeViolationException::class);

        (new TenantQueryGuard())->before(
            'insert into invoices (uuid, tenant_uuid, amount) values (?, ?, ?), (?, ?, ?)',
            ['row-1', 'tenant-a', 100, 'row-2', 'tenant-b', 200]
        );
    }

    /**
     * Build a production context whose container resolves 'logger' to the given logger.
     */
    private function prodContextWithLogger(object $logger, string $guardProd): ApplicationContext
    {
        $container = new class ($logger) implements ContainerInterface {
            public function __construct(private object $logger)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'logger' || $id === \Psr\Log\LoggerInterface::class) {
                    return $this->logger;
                }
                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'logger' || $id === \Psr\Log\LoggerInterface::class;
            }
        };

        $ctx = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'production');
        $ctx->setContainer($container);
        $ctx->mergeConfigDefaults('tenancy', [
            'enforcement' => ['guard' => ['dev' => 'throw', 'prod' => $guardProd]],
        ]);

        return $ctx;
    }
}
