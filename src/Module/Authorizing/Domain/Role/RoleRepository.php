<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Role;

interface RoleRepository
{
    public function define(string $key, string $label, RolePermissionSetValueObject $permissions, ?int $expectedRevision, bool $createIfAbsent): RoleEntity;

    public function retire(string $key, int $expectedRevision): RoleEntity;

    public function get(string $key): ?RoleEntity;

    /** @return list<RoleEntity> Up to limit + 1 rows, including lookahead. */
    public function roles(int $limit, ?string $afterRoleKey = null): array;
}
