<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Psr\Container\ContainerInterface;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioningRunner;
use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantAdministration;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantDomainAdministration;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantProvisioner;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantProvisioningRunner;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantRunner;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;
use Glueful\Extensions\Tenancy\Membership\AdvisoryMembershipRoleLock;
use Glueful\Extensions\Tenancy\Membership\ConfigRoleAuthority;
use Glueful\Extensions\Tenancy\Membership\MembershipRoleAuthority;
use Glueful\Extensions\Tenancy\Membership\MembershipRoleLock;

/** Always-on tenancy identity, lifecycle, and administration services. */
final class TenancyControlPlaneProvider extends ServiceProvider
{
    /** @return array<string, mixed> */
    public static function services(): array
    {
        return [
            TenantProvisioner::class => [
                'class' => ContractTenantProvisioner::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantProvisioningRunner::class => [
                'class' => ContractTenantProvisioningRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantAdministration::class => [
                'class' => ContractTenantAdministration::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantDomainAdministration::class => [
                'class' => ContractTenantDomainAdministration::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantContextRunner::class => [
                'class' => ContractTenantRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReleasedHostRepository::class => [
                'class' => ReleasedHostRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            MembershipRoleAuthority::class => [
                'factory' => [self::class, 'makeMembershipRoleAuthority'],
                'shared' => true,
            ],
            MembershipRoleLock::class => [
                'class' => AdvisoryMembershipRoleLock::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('tenancy', require __DIR__ . '/../config/tenancy.php');
    }

    public static function makeMembershipRoleAuthority(ContainerInterface $container): MembershipRoleAuthority
    {
        $context = $container->get(ApplicationContext::class);
        $configured = config(
            $context,
            'tenancy.membership.role_authority',
            ConfigRoleAuthority::class,
        );
        if (is_string($configured) && $configured !== ConfigRoleAuthority::class && $container->has($configured)) {
            $authority = $container->get($configured);
            if ($authority instanceof MembershipRoleAuthority) {
                return $authority;
            }
        }
        return new ConfigRoleAuthority();
    }

    public function boot(ApplicationContext $context): void
    {
        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEFAULT - 50,
            'glueful/tenancy'
        );

        $this->discoverCommands(
            'Glueful\\Extensions\\Tenancy\\Console',
            __DIR__ . '/Console'
        );
    }
}
