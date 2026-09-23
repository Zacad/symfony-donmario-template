<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListRoles;

final readonly class ListRolesQuery
{
    public function __construct(public int $limit = 50, public ?string $afterRoleKey = null)
    {
    }
}
