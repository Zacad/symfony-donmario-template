<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\RetireRole;

use App\Module\Authorizing\Domain\Capability\AuthorizingPermissionEnum;
use App\Module\Authorizing\Domain\Role\RoleRepository;
use App\Module\Authorizing\Infrastructure\Framework\Symfony\Security\AuthorizingVoter;
use App\Platform\Authorization\Authorize;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
#[Authorize(voter: AuthorizingVoter::class, permission: AuthorizingPermissionEnum::CatalogueManage, label: 'Manage authorization catalogue')]
final readonly class RetireRoleHandler
{
    public function __construct(private RoleRepository $roles)
    {
    }

    public function __invoke(RetireRoleCommand $command): RetireRoleResult
    {
        $role = $this->roles->retire($command->key, $command->expectedRevision);
        $retiredAt = $role->retiredAt();
        if (null === $retiredAt) {
            throw new \UnexpectedValueException('Invalid stored authorization role.');
        }

        return new RetireRoleResult($role->key(), $role->revision(), $retiredAt);
    }
}
