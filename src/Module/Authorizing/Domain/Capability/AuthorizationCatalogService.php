<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Capability;

use App\Module\Authorizing\Domain\Assignment\AssignmentChangeValueObject;
use App\Module\Authorizing\Domain\Assignment\AssignmentKindEnum;
use App\Module\Authorizing\Domain\Assignment\AssignmentOperationEnum;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use App\Module\Authorizing\Domain\Role\RolePermissionSetValueObject;

/** Compiler-fed inventory of permissions installed by application handlers. */
final readonly class AuthorizationCatalogService
{
    /** @var array<string, true> */
    private array $permissions;

    /**
     * @param list<array{module: string, key: string, label: string, access: string, operations: list<string>}> $capabilities
     */
    public function __construct(private array $capabilities)
    {
        $permissions = [];
        foreach ($capabilities as $capability) {
            $permissions[$capability['key']] = true;
        }
        $this->permissions = $permissions;
    }

    public function validateChange(AssignmentChangeValueObject $change): void
    {
        if (AssignmentOperationEnum::Remove === $change->operation || AssignmentKindEnum::Role === $change->reference->kind) {
            return;
        }

        if (!$this->isKnownPermission($change->reference->key)) {
            throw new InvalidAuthorizationInputException();
        }
    }

    public function isKnownPermission(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }

    /** @param list<string> $permissions */
    public function rolePermissionSet(array $permissions): RolePermissionSetValueObject
    {
        $permissionSet = new RolePermissionSetValueObject($permissions);
        foreach ($permissionSet->permissions as $permission) {
            if (!$this->isKnownPermission($permission)) {
                throw new InvalidAuthorizationInputException();
            }
        }

        return $permissionSet;
    }

    /**
     * @return list<array{module: string, key: string, label: string, access: string, operations: list<string>}>
     */
    public function after(?string $key, int $limit): array
    {
        $result = [];
        foreach ($this->capabilities as $capability) {
            if (null !== $key && strcmp($capability['key'], $key) <= 0) {
                continue;
            }
            $result[] = $capability;
            if (count($result) > $limit) {
                break;
            }
        }

        return $result;
    }
}
