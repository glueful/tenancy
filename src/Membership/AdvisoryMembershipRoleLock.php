<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Membership;

use Glueful\Bootstrap\ApplicationContext;
use LogicException;

final class AdvisoryMembershipRoleLock implements MembershipRoleLock
{
    public function lock(ApplicationContext $context, string $tenantUuid, string $role): void
    {
        $connection = db($context);
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('Membership role locks require an active transaction.');
        }
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }
        $statement = $connection->getPDO()->prepare(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))'
        );
        $statement->execute(['tenancy:role:' . $tenantUuid . ':' . trim($role)]);
    }

    public function lockMany(ApplicationContext $context, string $tenantUuid, array $roles): void
    {
        $roles = array_values(array_unique(array_filter(array_map('trim', $roles), static fn (
            string $role
        ): bool => $role !== '')));
        sort($roles, SORT_STRING);
        foreach ($roles as $role) {
            $this->lock($context, $tenantUuid, $role);
        }
    }
}
