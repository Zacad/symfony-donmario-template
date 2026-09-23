<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Assignment;

use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use App\Module\Authorizing\Domain\Role\RoleEntity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'authorizing_role_assignment', schema: 'public')]
#[ORM\Index(name: 'authorizing_role_assignment_role', columns: ['role_key'])]
class RoleAssignmentEntity
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'subject_id', type: UuidType::NAME)]
        private readonly Uuid $subjectId,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: RoleEntity::class)]
        #[ORM\JoinColumn(name: 'role_key', referencedColumnName: 'role_key', nullable: false)]
        private readonly RoleEntity $role,
    ) {
    }

    public static function assign(Uuid $subjectId, RoleEntity $role): self
    {
        if (null !== $role->retiredAt()) {
            throw new InvalidAuthorizationInputException();
        }

        return new self($subjectId, $role);
    }

    public function subjectId(): Uuid
    {
        return $this->subjectId;
    }

    public function role(): RoleEntity
    {
        return $this->role;
    }
}
