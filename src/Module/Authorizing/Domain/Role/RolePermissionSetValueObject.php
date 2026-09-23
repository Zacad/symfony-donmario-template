<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Role;

use App\Module\Authorizing\Domain\AuthorizationSyntaxValidator;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;

final readonly class RolePermissionSetValueObject
{
    /** @var list<string> */
    public array $permissions;

    /** @param list<string> $permissions */
    public function __construct(array $permissions)
    {
        if ([] === $permissions || count($permissions) > 4096 || count(array_unique($permissions)) !== count($permissions)) {
            throw new InvalidAuthorizationInputException();
        }
        foreach ($permissions as $permission) {
            AuthorizationSyntaxValidator::key($permission);
        }
        sort($permissions);
        $this->permissions = $permissions;
    }
}
