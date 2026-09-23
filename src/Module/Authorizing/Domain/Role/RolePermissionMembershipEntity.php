<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Role;

use App\Module\Authorizing\Domain\AuthorizationSyntaxValidator;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'authorizing_role_permission', schema: 'public')]
#[ORM\Index(name: 'authorizing_role_permission_role', columns: ['role_key'])]
#[ORM\Index(name: 'authorizing_role_permission_permission_role', columns: ['permission_key', 'role_key'])]
class RolePermissionMembershipEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: RoleEntity::class)]
        #[ORM\JoinColumn(name: 'role_key', referencedColumnName: 'role_key', nullable: false, onDelete: 'CASCADE')]
        private readonly RoleEntity $role,
        #[ORM\Id]
        #[ORM\Column(name: 'permission_key', length: 64)]
        private readonly string $permissionKey,
    ) {
        AuthorizationSyntaxValidator::key($permissionKey);
    }

    public function role(): RoleEntity
    {
        return $this->role;
    }

    public function permissionKey(): string
    {
        return $this->permissionKey;
    }
}
