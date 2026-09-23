<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Role;

use App\Module\Authorizing\Domain\AuthorizationSyntaxValidator;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'authorizing_role', schema: 'public')]
class RoleEntity
{
    #[ORM\Id]
    #[ORM\Column(name: 'role_key', length: 64)]
    private string $key;

    #[ORM\Column(length: 100)]
    private string $label;

    #[ORM\Column]
    private int $revision;

    #[ORM\Column(name: 'retired_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $retiredAt;

    private RolePermissionSetValueObject $permissionSet;

    private function __construct(string $key, string $label, int $revision, ?\DateTimeImmutable $retiredAt, RolePermissionSetValueObject $permissionSet)
    {
        $this->key = $key;
        $this->label = $label;
        $this->revision = $revision;
        $this->retiredAt = $retiredAt;
        $this->permissionSet = $permissionSet;
    }

    public static function create(
        string $key,
        string $label,
        RolePermissionSetValueObject $permissionSet,
        ?int $expectedRevision,
    ): self {
        AuthorizationSyntaxValidator::key($key);
        AuthorizationSyntaxValidator::label($label);
        if (null !== $expectedRevision) {
            throw new InvalidAuthorizationInputException();
        }

        return new self($key, $label, 1, null, $permissionSet);
    }

    /** @param list<string> $permissions */
    public static function reconstitute(string $key, string $label, int $revision, ?\DateTimeImmutable $retiredAt, array $permissions): self
    {
        try {
            AuthorizationSyntaxValidator::key($key);
            AuthorizationSyntaxValidator::label($label);
            if ($revision < 1 || $revision > 2147483647 || (null !== $retiredAt && 1 === $revision)) {
                throw new InvalidAuthorizationInputException();
            }

            return new self($key, $label, $revision, $retiredAt, new RolePermissionSetValueObject($permissions));
        } catch (InvalidAuthorizationInputException) {
            throw new \UnexpectedValueException('Invalid stored authorization role.');
        }
    }

    public function define(string $label, RolePermissionSetValueObject $permissionSet, ?int $expectedRevision, bool $createIfAbsent): bool
    {
        AuthorizationSyntaxValidator::label($label);
        if (null !== $expectedRevision && $createIfAbsent) {
            throw new InvalidAuthorizationInputException();
        }
        if ($createIfAbsent) {
            if (null !== $this->retiredAt || $this->label !== $label || $this->permissionSet->permissions !== $permissionSet->permissions) {
                throw new InvalidAuthorizationInputException();
            }

            return false;
        }
        $this->assertActiveRevision($expectedRevision);
        $this->label = $label;
        $this->permissionSet = $permissionSet;
        ++$this->revision;

        return true;
    }

    public function retire(int $expectedRevision, \DateTimeImmutable $retiredAt): bool
    {
        $this->assertRevision($expectedRevision);
        if (null !== $this->retiredAt) {
            return false;
        }
        if (2147483647 === $this->revision) {
            throw new InvalidAuthorizationInputException();
        }
        ++$this->revision;
        $this->retiredAt = $retiredAt;

        return true;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function retiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissionSet->permissions;
    }

    private function assertActiveRevision(?int $expectedRevision): void
    {
        if (null !== $this->retiredAt) {
            throw new InvalidAuthorizationInputException();
        }
        $this->assertRevision($expectedRevision);
        if (2147483647 === $this->revision) {
            throw new InvalidAuthorizationInputException();
        }
    }

    private function assertRevision(?int $expectedRevision): void
    {
        if (null === $expectedRevision || $expectedRevision < 1 || $this->revision !== $expectedRevision) {
            throw new InvalidAuthorizationInputException();
        }
    }
}
