<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListRoles;

final readonly class ListRolesResult
{
    /** @param list<RoleListItemResult> $roles */
    public function __construct(public array $roles, public ?string $nextAfterRoleKey = null)
    {
    }
}
