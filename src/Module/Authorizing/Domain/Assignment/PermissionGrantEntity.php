<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Assignment;

use App\Module\Authorizing\Domain\AuthorizationSyntaxValidator;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'authorizing_permission_grant', schema: 'public')]
class PermissionGrantEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'subject_id', type: UuidType::NAME)]
        private readonly Uuid $subjectId,
        #[ORM\Id]
        #[ORM\Column(name: 'permission_key', length: 64)]
        private readonly string $permissionKey,
    ) {
        AuthorizationSyntaxValidator::key($permissionKey);
    }

    public function subjectId(): Uuid
    {
        return $this->subjectId;
    }

    public function permissionKey(): string
    {
        return $this->permissionKey;
    }
}
