<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\DefineRole;

use App\Module\Authorizing\Domain\Capability\AuthorizationCatalogService;
use App\Module\Authorizing\Domain\Capability\AuthorizingPermissionEnum;
use App\Module\Authorizing\Domain\Role\RoleRepository;
use App\Module\Authorizing\Infrastructure\Framework\Symfony\Security\AuthorizingVoter;
use App\Platform\Authorization\Authorize;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
#[Authorize(voter: AuthorizingVoter::class, permission: AuthorizingPermissionEnum::CatalogueManage, label: 'Manage authorization catalogue')]
final readonly class DefineRoleHandler
{
    public function __construct(private AuthorizationCatalogService $catalog, private RoleRepository $roles)
    {
    }

    public function __invoke(DefineRoleCommand $command): DefineRoleResult
    {
        $permissions = $this->catalog->rolePermissionSet($command->permissions);
        $role = $this->roles->define($command->key, $command->label, $permissions, $command->expectedRevision, $command->createIfAbsent);

        return new DefineRoleResult($role->key(), $role->label(), $role->revision(), $role->retiredAt(), $role->permissions());
    }
}
