<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'authorizing_resource_role_assignment', schema: 'public')]
#[ORM\UniqueConstraint(name: 'authorizing_resource_role_natural_unique', columns: ['account_id', 'role_key', 'resource_type', 'resource_id'])]
#[ORM\Index(name: 'authorizing_resource_role_account_cursor', columns: ['account_id', 'id'])]
class ResourceRoleAssignment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\Column(name: 'account_id', type: UuidType::NAME)]
        private Uuid $accountId,
        #[ORM\Column(name: 'role_key', length: 64)]
        private string $roleKey,
        #[ORM\Column(name: 'resource_type', length: 64)]
        private string $resourceType,
        #[ORM\Column(name: 'resource_id', type: UuidType::NAME)]
        private Uuid $resourceId,
    ) {
        AuthorizationInput::key($roleKey);
        AuthorizationInput::key($resourceType);
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function accountId(): Uuid
    {
        return $this->accountId;
    }

    public function roleKey(): string
    {
        return $this->roleKey;
    }

    public function resourceType(): string
    {
        return $this->resourceType;
    }

    public function resourceId(): Uuid
    {
        return $this->resourceId;
    }
}
