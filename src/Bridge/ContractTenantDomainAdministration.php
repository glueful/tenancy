<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Events\Contracts\BaseEvent;
use Glueful\Extensions\Contracts\Tenancy\DomainReverificationResult;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\HostCooldownException;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;
use Glueful\Extensions\Tenancy\Events\HostReleased;
use Glueful\Extensions\Tenancy\Events\DomainReverificationFailed;
use Glueful\Extensions\Tenancy\Events\DomainReverified;
use Glueful\Extensions\Tenancy\Events\DomainRevoked;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Models\TenantDomain;
use Glueful\Extensions\Tenancy\Resolution\DnsTxtLookup;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;
use Glueful\Helpers\Utils;
use Psr\Log\LoggerInterface;

final class ContractTenantDomainAdministration implements TenantDomainAdministration
{
    public function __construct(
        private readonly DnsTxtLookup $dns = new DnsTxtLookup(),
        private readonly ReleasedHostRepository $cooldown = new ReleasedHostRepository(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function addDomain(ApplicationContext $c, string $tenantUuid, string $host): array
    {
        $host = $this->registeredHost($c, $host);

        return db($c)->transaction(function () use ($c, $tenantUuid, $host): array {
            $this->guardCooldownAndConsume($c, $host, $tenantUuid);
            $token = bin2hex(random_bytes(32));
            $domain = TenantDomain::create($c, [
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $tenantUuid,
                'host' => $host,
                'verification_token' => $token,
            ]);

            return ['uuid' => $domain->uuid, 'token' => $token];
        });
    }

    public function verifyDomain(ApplicationContext $c, string $domainUuid): string
    {
        $domain = TenantDomain::query($c)->where('uuid', $domainUuid)->first();
        if ($domain === null) {
            throw new \RuntimeException('Tenant domain was not found.');
        }
        if ((string) $domain->verification_status !== TenantDomain::VERIFICATION_PENDING) {
            throw new \DomainException(
                'Only pending domains may use initial verification; use re-verification instead.'
            );
        }

        $outcome = $this->classify((string) $domain->host, (string) $domain->verification_token);
        $now = db($c)->getDriverName() === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'now()';
        if ($outcome !== 'verified') {
            $stmt = db($c)->getPDO()->prepare(
                "UPDATE tenant_domains SET last_check_status = ?, last_checked_at = {$now}, "
                . "consecutive_failures = 0, first_failure_at = NULL, updated_at = {$now} WHERE uuid = ?"
            );
            $stmt->execute([$outcome, $domainUuid]);
            return TenantDomain::VERIFICATION_PENDING;
        }

        $stmt = db($c)->getPDO()->prepare(
            "UPDATE tenant_domains SET verification_status = 'verified', verified_at = {$now}, "
            . "last_check_status = 'verified', last_checked_at = {$now}, consecutive_failures = 0, "
            . "first_failure_at = NULL, updated_at = {$now} WHERE uuid = ?"
        );
        $stmt->execute([$domainUuid]);

        return TenantDomain::VERIFICATION_VERIFIED;
    }

    public function reverifyDomain(
        ApplicationContext $c,
        string $domainUuid
    ): DomainReverificationResult {
        $snapshot = TenantDomain::query($c)->where('uuid', $domainUuid)->first();
        if ($snapshot === null) {
            return new DomainReverificationResult('ineligible', null, 'none', 0, null);
        }

        $status = (string) $snapshot->verification_status;
        $token = (string) ($snapshot->verification_token ?? '');
        $eligible = [TenantDomain::VERIFICATION_VERIFIED, TenantDomain::VERIFICATION_REVOKED];
        if ($token === '' || !in_array($status, $eligible, true)) {
            if (!in_array($status, [TenantDomain::VERIFICATION_PENDING, ...$eligible], true)) {
                $this->logger?->warning('tenancy.reverify.unknown_status', [
                    'domain_uuid' => $domainUuid,
                    'verification_status' => $status,
                ]);
            }
            return new DomainReverificationResult(
                'ineligible',
                $status,
                'none',
                (int) ($snapshot->consecutive_failures ?? 0),
                null
            );
        }

        $host = (string) $snapshot->host;
        $snapshotCheckedAt = $snapshot->last_checked_at === null
            ? null
            : (string) $snapshot->last_checked_at;

        // Blocking DNS I/O must happen before the host advisory lock and row transaction.
        $outcome = $this->classify($host, $token);

        return db($c)->transaction(function () use (
            $c,
            $domainUuid,
            $host,
            $token,
            $status,
            $snapshotCheckedAt,
            $outcome
        ): DomainReverificationResult {
            $this->cooldown->lockHost($c, $host);

            $stmt = db($c)->getPDO()->prepare(
                'SELECT tenant_uuid, host, verification_token, verification_status, last_checked_at, '
                . 'consecutive_failures FROM tenant_domains WHERE uuid = ? FOR UPDATE'
            );
            $stmt->execute([$domainUuid]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $rowCheckedAt = is_array($row) && $row['last_checked_at'] !== null
                ? (string) $row['last_checked_at']
                : null;
            if (
                !is_array($row)
                || (string) $row['host'] !== $host
                || (string) $row['verification_token'] !== $token
                || (string) $row['verification_status'] !== $status
                || $rowCheckedAt !== $snapshotCheckedAt
            ) {
                return new DomainReverificationResult(
                    'stale',
                    is_array($row) ? (string) $row['verification_status'] : null,
                    'none',
                    is_array($row) ? (int) $row['consecutive_failures'] : 0,
                    null
                );
            }

            $tenantUuid = (string) $row['tenant_uuid'];
            if ($outcome === 'verified') {
                $transition = $status === TenantDomain::VERIFICATION_REVOKED ? 'restored' : 'none';
                $restoreSql = $transition === 'restored'
                    ? ", verification_status = 'verified', verified_at = now()"
                    : '';
                $update = db($c)->getPDO()->prepare(
                    "UPDATE tenant_domains SET last_check_status = 'verified', last_checked_at = now(), "
                    . "consecutive_failures = 0, first_failure_at = NULL, updated_at = now(){$restoreSql} "
                    . 'WHERE uuid = ? RETURNING last_checked_at'
                );
                $update->execute([$domainUuid]);
                $checkedAt = (string) $update->fetchColumn();
                if ($transition === 'restored') {
                    $this->afterCommit($c, new DomainReverified(
                        $domainUuid,
                        $tenantUuid,
                        $host,
                        'verified',
                        0,
                        TenantDomain::VERIFICATION_VERIFIED
                    ));
                }
                return new DomainReverificationResult(
                    'verified',
                    TenantDomain::VERIFICATION_VERIFIED,
                    $transition,
                    0,
                    $checkedAt
                );
            }

            $threshold = (int) config($c, 'tenancy.domains.reverification.failure_threshold', 3);
            $graceHours = (int) config($c, 'tenancy.domains.reverification.grace_hours', 24);
            $evaluate = db($c)->getPDO()->prepare(
                'WITH updated AS (UPDATE tenant_domains SET last_check_status = ?, last_checked_at = now(), '
                . 'consecutive_failures = consecutive_failures + 1, '
                . 'first_failure_at = COALESCE(first_failure_at, now()), updated_at = now() '
                . 'WHERE uuid = ? RETURNING consecutive_failures, first_failure_at, last_checked_at) '
                . 'SELECT consecutive_failures, last_checked_at, CASE WHEN consecutive_failures >= ? '
                . 'AND (now() - first_failure_at) >= make_interval(hours => CAST(? AS integer)) '
                . 'THEN 1 ELSE 0 END AS revoke_ready FROM updated'
            );
            $evaluate->execute([$outcome, $domainUuid, $threshold, $graceHours]);
            $evaluation = $evaluate->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($evaluation)) {
                throw new \RuntimeException('Domain re-verification state could not be persisted.');
            }
            $failures = (int) $evaluation['consecutive_failures'];
            $transition = 'none';
            if (
                $status === TenantDomain::VERIFICATION_VERIFIED
                && (int) $evaluation['revoke_ready'] === 1
            ) {
                $revoke = db($c)->getPDO()->prepare(
                    "UPDATE tenant_domains SET verification_status = 'revoked', updated_at = now() WHERE uuid = ?"
                );
                $revoke->execute([$domainUuid]);
                $transition = 'revoked';
            }

            $resultStatus = $transition === 'revoked'
                ? TenantDomain::VERIFICATION_REVOKED
                : $status;
            $this->afterCommit($c, new DomainReverificationFailed(
                $domainUuid,
                $tenantUuid,
                $host,
                $outcome,
                $failures,
                $resultStatus
            ));
            if ($transition === 'revoked') {
                $this->afterCommit($c, new DomainRevoked(
                    $domainUuid,
                    $tenantUuid,
                    $host,
                    $outcome,
                    $failures,
                    TenantDomain::VERIFICATION_REVOKED
                ));
            }

            return new DomainReverificationResult(
                $outcome,
                $resultStatus,
                $transition,
                $failures,
                (string) $evaluation['last_checked_at']
            );
        });
    }

    public function disableDomain(ApplicationContext $c, string $domainUuid): void
    {
        $this->assertNotRequiredHost($c, $domainUuid);
        $this->setStatus($c, $domainUuid, TenantDomain::STATUS_DISABLED);
    }

    public function enableDomain(ApplicationContext $c, string $domainUuid): void
    {
        $this->setStatus($c, $domainUuid, TenantDomain::STATUS_ACTIVE);
    }

    public function removeDomain(ApplicationContext $c, string $domainUuid): void
    {
        $this->assertNotRequiredHost($c, $domainUuid);
        $this->releaseDomain($c, $domainUuid);
    }

    public function releaseDomain(ApplicationContext $c, string $domainUuid): void
    {
        db($c)->transaction(function () use ($c, $domainUuid): void {
            $domain = TenantDomain::query($c)->where('uuid', $domainUuid)->first();
            if ($domain === null) {
                throw new \RuntimeException('Tenant domain was not found.');
            }
            $host = HostNormalizer::normalize($domain->host);
            $tenantUuid = (string) $domain->tenant_uuid;
            $this->cooldown->lockHost($c, $host);
            if (db($c)->table('tenant_domains')->where('uuid', $domainUuid)->forceDelete() !== 1) {
                throw new \RuntimeException('Tenant domain was not found.');
            }
            $days = (int) config($c, 'tenancy.domains.release_cooldown_days', 30);
            $retainedUntil = gmdate('Y-m-d H:i:s', time() + ($days * 86400));
            $this->cooldown->upsertTombstone($c, $host, $tenantUuid, $retainedUntil);
            $this->afterCommit($c, new HostReleased($host, $tenantUuid, $retainedUntil));
        });
    }

    public function overrideCooldownAndClaim(
        ApplicationContext $c,
        string $tenantUuid,
        string $host
    ): array {
        return db($c)->transaction(function () use ($c, $tenantUuid, $host): array {
            $tenant = Tenant::query($c)->where('uuid', $tenantUuid)->where('status', 'active')->first();
            if ($tenant === null) {
                throw new \InvalidArgumentException('Override target must be an active tenant.');
            }
            $host = $this->registeredHost($c, $host);
            $this->cooldown->lockHost($c, $host);
            $this->cooldown->consume($c, $host);
            $token = bin2hex(random_bytes(32));
            $domain = TenantDomain::create($c, [
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $tenantUuid,
                'host' => $host,
                'verification_token' => $token,
            ]);

            return ['uuid' => $domain->uuid, 'token' => $token];
        });
    }

    public function listDomains(ApplicationContext $c, string $tenantUuid): array
    {
        $result = [];
        $domains = TenantDomain::query($c)
            ->where('tenant_uuid', $tenantUuid)
            ->orderBy('created_at', 'asc')
            ->orderBy('uuid', 'asc')
            ->get();
        foreach ($domains as $domain) {
            $result[] = $this->projection($domain, false);
        }

        return $result;
    }

    public function getDomain(ApplicationContext $c, string $domainUuid): ?array
    {
        $domain = TenantDomain::query($c)->where('uuid', $domainUuid)->first();

        return $domain === null ? null : $this->projection($domain, true);
    }

    public function addPreverifiedDomain(
        ApplicationContext $c,
        string $tenantUuid,
        string $host
    ): string {
        $host = HostNormalizer::normalize($host);
        HostNormalizer::validateForRegistration(
            $host,
            (array) config($c, 'tenancy.public_origin', []),
            true
        );
        return db($c)->transaction(function () use ($c, $tenantUuid, $host): string {
            $this->guardCooldownAndConsume($c, $host, $tenantUuid);
            $existing = TenantDomain::query($c)->where('host', $host)->first();
            if ($existing !== null) {
                if ($existing->tenant_uuid !== $tenantUuid) {
                    throw new \DomainException('Host is already owned by another tenant.');
                }
                db($c)->table('tenant_domains')->where('uuid', $existing->uuid)->update([
                    'verification_status' => TenantDomain::VERIFICATION_VERIFIED,
                    'status' => TenantDomain::STATUS_ACTIVE,
                    'verified_at' => $existing->verified_at ?? gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                return $existing->uuid;
            }

            $domain = TenantDomain::create($c, [
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $tenantUuid,
                'host' => $host,
            ]);
            db($c)->table('tenant_domains')->where('uuid', $domain->uuid)->update([
                'verification_status' => TenantDomain::VERIFICATION_VERIFIED,
                'status' => TenantDomain::STATUS_ACTIVE,
                'verified_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

            return $domain->uuid;
        });
    }

    private function guardCooldownAndConsume(
        ApplicationContext $c,
        string $host,
        string $tenantUuid
    ): void {
        $this->cooldown->lockHost($c, $host);
        $tombstone = $this->cooldown->activeTombstone($c, $host, gmdate('Y-m-d H:i:s'));
        if ($tombstone !== null && !hash_equals($tombstone['released_by_tenant'], $tenantUuid)) {
            throw new HostCooldownException($tombstone['retained_until']);
        }
        $this->cooldown->consume($c, $host);
    }

    private function afterCommit(ApplicationContext $c, BaseEvent $event): void
    {
        db($c)->afterCommit(static function () use ($c, $event): void {
            $container = $c->getContainer();
            if ($container->has(EventService::class)) {
                $container->get(EventService::class)->dispatch($event);
            }
        });
    }

    private function registeredHost(ApplicationContext $c, string $host): string
    {
        $host = HostNormalizer::normalize($host);
        HostNormalizer::validateForRegistration($host, (array) config($c, 'tenancy.public_origin', []));

        return $host;
    }

    private function setStatus(ApplicationContext $c, string $domainUuid, string $status): void
    {
        $changed = db($c)->table('tenant_domains')->where('uuid', $domainUuid)->update([
            'status' => $status,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($changed === 0 && $this->getDomain($c, $domainUuid) === null) {
            throw new \RuntimeException('Tenant domain was not found.');
        }
    }

    private function assertNotRequiredHost(ApplicationContext $c, string $domainUuid): void
    {
        $container = $c->getContainer();
        if (!$container->has(FullTenantResolutionReadiness::class)) {
            return;
        }
        $readiness = $container->get(FullTenantResolutionReadiness::class);
        if (!$readiness instanceof FullTenantResolutionReadiness || !$readiness->isReady($c)) {
            return;
        }
        $domain = $this->getDomain($c, $domainUuid);
        if ($domain === null) {
            throw new \RuntimeException('Tenant domain was not found.');
        }
        $configured = config($c, 'tenancy.public_origin.default_hosts', []);
        $required = [];
        foreach (is_array($configured) ? $configured : [] as $host) {
            if (is_string($host)) {
                $required[] = HostNormalizer::normalize($host);
            }
        }
        if (in_array($domain['host'], $required, true)) {
            throw new \DomainException('A required default host cannot be disabled or removed.');
        }
    }

    /** @return array<string,mixed> */
    private function projection(TenantDomain $domain, bool $withTenant): array
    {
        $result = [
            'uuid' => $domain->uuid,
            'host' => $domain->host,
            'verification_status' => $domain->verification_status,
            'status' => $domain->status,
            'last_checked_at' => $domain->last_checked_at,
            'last_check_status' => $domain->last_check_status,
            'consecutive_failures' => (int) ($domain->consecutive_failures ?? 0),
        ];
        if ($withTenant) {
            $result = ['tenant_uuid' => $domain->tenant_uuid] + $result;
        }

        return $result;
    }

    /** @return 'verified'|'mismatch'|'dns_error' */
    private function classify(string $host, string $token): string
    {
        $result = $this->dns->lookupStructured('_thallo-verify.' . $host);
        if ($result->isError()) {
            return 'dns_error';
        }

        return in_array($token, $result->records, true) ? 'verified' : 'mismatch';
    }
}
