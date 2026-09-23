<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListRoles;

use App\Module\Authorizing\Domain\Capability\AuthorizingPermissionEnum;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use App\Module\Authorizing\Domain\Role\RoleEntity;
use App\Module\Authorizing\Domain\Role\RoleRepository;
use App\Module\Authorizing\Infrastructure\Framework\Symfony\Security\AuthorizingVoter;
use App\Platform\Authorization\Authorize;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
#[Authorize(voter: AuthorizingVoter::class, permission: AuthorizingPermissionEnum::CatalogueManage, label: 'Manage authorization catalogue')]
final readonly class ListRolesHandler
{
    public function __construct(private RoleRepository $roles)
    {
    }

    public function __invoke(ListRolesQuery $query): ListRolesResult
    {
        if ($query->limit < 1 || $query->limit > 100) {
            throw new InvalidAuthorizationInputException();
        }

        $roles = $this->roles->roles($query->limit, $query->afterRoleKey);
        $hasMore = count($roles) > $query->limit;
        $roles = array_slice($roles, 0, $query->limit);
        $results = array_map(static fn (RoleEntity $role): RoleListItemResult => new RoleListItemResult(
            $role->key(),
            $role->label(),
            $role->revision(),
            $role->retiredAt(),
            $role->permissions(),
        ), $roles);

        return new ListRolesResult($results, $hasMore && [] !== $roles ? $roles[array_key_last($roles)]->key() : null);
    }
}
