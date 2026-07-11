<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Cooldown;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;
use LogicException;
use PDO;

/** PostgreSQL-serialized access to the system-global released-host ledger. */
final class ReleasedHostRepository
{
    private const LOCK_PREFIX = 'tenancy:host:';

    public function lockHost(ApplicationContext $c, string $host): void
    {
        if (db($c)->transactionLevel() === 0) {
            throw new LogicException('Host advisory locks require an active transaction.');
        }
        if (db($c)->getDriverName() === 'sqlite') {
            return;
        }
        $stmt = db($c)->getPDO()->prepare(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))'
        );
        $stmt->execute([self::LOCK_PREFIX . HostNormalizer::normalize($host)]);
    }

    /** @param list<string> $hosts */
    public function lockHosts(ApplicationContext $c, array $hosts): void
    {
        $normalized = [];
        foreach ($hosts as $host) {
            $normalized[HostNormalizer::normalize($host)] = true;
        }
        $hosts = array_keys($normalized);
        sort($hosts, SORT_STRING);
        foreach ($hosts as $host) {
            $this->lockHost($c, $host);
        }
    }

    public function upsertTombstone(
        ApplicationContext $c,
        string $host,
        string $releasedByTenant,
        string $retainedUntil
    ): void {
        $this->assertTransaction($c);
        $host = HostNormalizer::normalize($host);
        $owner = db($c)->getPDO()->prepare(
            'SELECT released_by_tenant FROM released_hosts WHERE host = ?'
        );
        $owner->execute([$host]);
        $existing = $owner->fetchColumn();
        if ($existing !== false && !hash_equals((string) $existing, $releasedByTenant)) {
            throw new LogicException('Existing host tombstone belongs to another release owner.');
        }

        $sql = db($c)->getDriverName() === 'sqlite'
            ? 'INSERT INTO released_hosts (host,released_by_tenant,retained_until,created_at) '
                . 'VALUES (?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(host) DO UPDATE SET '
                . 'retained_until=MAX(released_hosts.retained_until,excluded.retained_until)'
            : 'INSERT INTO released_hosts (host,released_by_tenant,retained_until,created_at) '
                . 'VALUES (?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(host) DO UPDATE SET '
                . 'retained_until=GREATEST(released_hosts.retained_until,EXCLUDED.retained_until)';
        db($c)->getPDO()->prepare($sql)->execute([$host, $releasedByTenant, $retainedUntil]);
    }

    /** @return array{host:string,released_by_tenant:string,retained_until:string}|null */
    public function activeTombstone(ApplicationContext $c, string $host, string $now): ?array
    {
        $stmt = db($c)->getPDO()->prepare(
            'SELECT host,released_by_tenant,retained_until FROM released_hosts '
            . 'WHERE host=? AND retained_until>?'
        );
        $stmt->execute([HostNormalizer::normalize($host), $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function consume(ApplicationContext $c, string $host): void
    {
        $this->assertTransaction($c);
        db($c)->getPDO()->prepare('DELETE FROM released_hosts WHERE host=?')
            ->execute([HostNormalizer::normalize($host)]);
    }

    public function pruneExpired(ApplicationContext $c, string $now): int
    {
        $stmt = db($c)->getPDO()->prepare(
            'SELECT host FROM released_hosts WHERE retained_until<=? ORDER BY host'
        );
        $stmt->execute([$now]);
        $deleted = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $host) {
            db($c)->transaction(function () use ($c, $host, $now, &$deleted): void {
                $this->lockHost($c, (string) $host);
                $delete = db($c)->getPDO()->prepare(
                    'DELETE FROM released_hosts WHERE host=? AND retained_until<=?'
                );
                $delete->execute([$host, $now]);
                $deleted += $delete->rowCount();
            });
        }

        return $deleted;
    }

    private function assertTransaction(ApplicationContext $c): void
    {
        if (db($c)->transactionLevel() === 0) {
            throw new LogicException('Cooldown mutation requires an active transaction.');
        }
    }
}
