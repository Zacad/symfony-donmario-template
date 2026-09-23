<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\GetRole;

use App\Module\Authorizing\Domain\Capability\AuthorizingPermissionEnum;
use App\Module\Authorizing\Domain\Role\RoleRepository;
use App\Module\Authorizing\Infrastructure\Framework\Symfony\Security\AuthorizingVoter;
use App\Platform\Authorization\Authorize;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
#[Authorize(voter: AuthorizingVoter::class, permission: AuthorizingPermissionEnum::CatalogueManage, label: 'Manage authorization catalogue')]
final readonly class GetRoleHandler
{
    public function __construct(private RoleRepository $roles)
    {
    }

    public function __invoke(GetRoleQuery $query): ?GetRoleResult
    {
        $role = $this->roles->get($query->key);

        return null === $role ? null : new GetRoleResult($role->key(), $role->label(), $role->revision(), $role->retiredAt(), $role->permissions());
    }
}
