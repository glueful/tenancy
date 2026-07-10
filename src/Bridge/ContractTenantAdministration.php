<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Tenancy\Exceptions\InvalidHostException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Models\TenantMembership;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;
use Glueful\Helpers\Utils;

final class ContractTenantAdministration implements TenantAdministration
{
    private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public function create(
        ApplicationContext $c,
        string $slug,
        string $name,
        string $ownerUserUuid
    ): string {
        $slug = strtolower(trim($slug));
        $name = trim($name);
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || $name === '') {
            throw new \InvalidArgumentException('Tenant slug or name is invalid.');
        }
        $this->assertSlugAvailable($c, $slug);

        $tenantUuid = Utils::generateNanoID(12);
        db($c)->transaction(function () use ($c, $tenantUuid, $slug, $name, $ownerUserUuid): void {
            Tenant::create($c, [
                'uuid' => $tenantUuid,
                'slug' => $slug,
                'name' => $name,
            ]);
            db($c)->table('tenants')->where('uuid', $tenantUuid)->update(['status' => 'provisioning']);

            $membership = TenantMembership::create($c, [
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $tenantUuid,
                'user_uuid' => $ownerUserUuid,
            ]);
            db($c)->table('tenant_memberships')->where('uuid', $membership->uuid)->update([
                'role' => 'owner',
                'status' => 'active',
            ]);
        });

        return $tenantUuid;
    }

    public function suspend(ApplicationContext $c, string $tenantUuid): void
    {
        $this->transition($c, $tenantUuid, 'active', 'suspended', 'suspend requires active state');
    }

    public function reactivate(ApplicationContext $c, string $tenantUuid): void
    {
        $this->transition($c, $tenantUuid, 'suspended', 'active', 'reactivate requires suspended state');
    }

    public function markActive(ApplicationContext $c, string $tenantUuid): void
    {
        $this->transition($c, $tenantUuid, 'provisioning', 'active', 'markActive requires provisioning state');
    }

    public function listTenants(ApplicationContext $c, ?string $status = null): array
    {
        $query = Tenant::query($c)->orderBy('created_at', 'asc')->orderBy('uuid', 'asc');
        if ($status !== null) {
            $query->where('status', $status);
        }

        $result = [];
        foreach ($query->get() as $tenant) {
            $result[] = $this->tenantProjection($tenant);
        }

        return $result;
    }

    public function getTenant(ApplicationContext $c, string $tenantUuid): ?array
    {
        $tenant = Tenant::query($c)->where('uuid', $tenantUuid)->first();

        return $tenant === null ? null : $this->tenantProjection($tenant);
    }

    public function listTenantsForUser(ApplicationContext $c, string $userUuid): array
    {
        $rows = db($c)->getPDO()->prepare(
            "SELECT t.uuid,t.slug,t.name,t.status FROM tenants t "
            . "INNER JOIN tenant_memberships m ON m.tenant_uuid=t.uuid "
            . "WHERE m.user_uuid=? AND m.status='active' AND t.status='active' AND t.deleted_at IS NULL "
            . 'ORDER BY t.created_at ASC,t.uuid ASC'
        );
        $rows->execute([$userUuid]);

        return array_values($rows->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function listMembers(ApplicationContext $c, string $tenantUuid): array
    {
        $result = [];
        $members = TenantMembership::query($c)
            ->where('tenant_uuid', $tenantUuid)
            ->orderBy('created_at', 'asc')
            ->orderBy('uuid', 'asc')
            ->get();
        foreach ($members as $member) {
            $result[] = [
                'uuid' => $member->uuid,
                'user_uuid' => $member->user_uuid,
                'role' => $member->role,
                'status' => $member->status,
            ];
        }

        return $result;
    }

    public function addMember(
        ApplicationContext $c,
        string $tenantUuid,
        string $userUuid,
        string $role
    ): void {
        $this->assertRole($c, $role);
        db($c)->transaction(function () use ($c, $tenantUuid, $userUuid, $role): void {
            $existing = TenantMembership::query($c)
                ->where('tenant_uuid', $tenantUuid)
                ->where('user_uuid', $userUuid)
                ->first();
            if ($existing === null) {
                $existing = TenantMembership::create($c, [
                    'uuid' => Utils::generateNanoID(12),
                    'tenant_uuid' => $tenantUuid,
                    'user_uuid' => $userUuid,
                ]);
            }
            db($c)->table('tenant_memberships')->where('uuid', $existing->uuid)->update([
                'role' => $role,
                'status' => 'active',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        });
    }

    public function removeMember(ApplicationContext $c, string $tenantUuid, string $userUuid): void
    {
        db($c)->transaction(function () use ($c, $tenantUuid, $userUuid): void {
            $this->assertNotFinalOwner($c, $tenantUuid, $userUuid);
            db($c)->table('tenant_memberships')
                ->where('tenant_uuid', $tenantUuid)
                ->where('user_uuid', $userUuid)
                ->delete();
        });
    }

    public function setMemberRole(
        ApplicationContext $c,
        string $tenantUuid,
        string $userUuid,
        string $role
    ): void {
        $this->assertRole($c, $role);
        db($c)->transaction(function () use ($c, $tenantUuid, $userUuid, $role): void {
            if ($role !== 'owner') {
                $this->assertNotFinalOwner($c, $tenantUuid, $userUuid);
            }
            $changed = db($c)->table('tenant_memberships')
                ->where('tenant_uuid', $tenantUuid)
                ->where('user_uuid', $userUuid)
                ->update(['role' => $role, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            if ($changed === 0) {
                throw new \RuntimeException('Tenant membership was not found.');
            }
        });
    }

    private function transition(
        ApplicationContext $c,
        string $tenantUuid,
        string $from,
        string $to,
        string $message
    ): void {
        $changed = db($c)->table('tenants')
            ->where('uuid', $tenantUuid)
            ->where('status', $from)
            ->update(['status' => $to, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        if ($changed === 0) {
            throw new \RuntimeException($message);
        }
    }

    private function assertSlugAvailable(ApplicationContext $c, string $slug): void
    {
        /** @var array<string,mixed> $origin */
        $origin = (array) config($c, 'tenancy.public_origin', []);
        $base = $origin['base_domain'] ?? null;
        if (is_string($base) && $base !== '') {
            HostNormalizer::validateForRegistration(
                HostNormalizer::normalize($slug . '.' . $base),
                $origin
            );
            return;
        }
        $reserved = $origin['reserved_labels'] ?? [];
        if (is_array($reserved) && in_array($slug, $reserved, true)) {
            throw new InvalidHostException('Tenant slug uses a reserved platform label.');
        }
    }

    private function assertRole(ApplicationContext $c, string $role): void
    {
        $roles = config($c, 'tenancy.membership.roles', ['owner', 'admin', 'member', 'viewer']);
        if (!is_array($roles) || !in_array($role, $roles, true)) {
            throw new \InvalidArgumentException('Unknown tenant membership role.');
        }
    }

    private function assertNotFinalOwner(
        ApplicationContext $c,
        string $tenantUuid,
        string $userUuid
    ): void {
        $sql = "SELECT user_uuid FROM tenant_memberships WHERE tenant_uuid=? "
            . "AND role='owner' AND status='active'";
        if (db($c)->getDriverName() !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $statement = db($c)->getPDO()->prepare($sql);
        $statement->execute([$tenantUuid]);
        $owners = $statement->fetchAll(\PDO::FETCH_COLUMN);
        if (count($owners) === 1 && $owners[0] === $userUuid) {
            throw new \DomainException('Cannot remove or demote the final active owner');
        }
    }

    /** @return array{uuid:string,slug:string,name:string,status:string} */
    private function tenantProjection(Tenant $tenant): array
    {
        return [
            'uuid' => $tenant->uuid,
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'status' => $tenant->status,
        ];
    }
}
