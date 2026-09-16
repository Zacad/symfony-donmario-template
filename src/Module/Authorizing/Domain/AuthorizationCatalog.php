<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain;

final class AuthorizationCatalog
{
    private const array PERMISSIONS = [
        'task_tracking.task.create' => null,
        'task_tracking.task.view' => 'task_tracking.task',
        'task_tracking.task.complete' => 'task_tracking.task',
        'authorizing.manage' => null,
    ];

    private const array ROLES = [
        'task_tracking.creator' => ['task_tracking.task.create'],
        'task_tracking.reader' => ['task_tracking.task.view'],
        'task_tracking.editor' => ['task_tracking.task.view', 'task_tracking.task.complete'],
        'authorizing.administrator' => ['authorizing.manage'],
    ];

    public function validateChange(AssignmentChange $change): void
    {
        // Retired keys/types must remain removable, even if their catalogue scope changed.
        if ('remove' === $change->operation) {
            return;
        }

        if ('permission' === $change->kind) {
            if (!$this->isKnownPermission($change->key)) {
                throw new InvalidAuthorizationInput();
            }
            $this->validatePermissionScope($change->key, $change->scope, $change->resourceType);

            return;
        }

        if (!isset(self::ROLES[$change->key])) {
            throw new InvalidAuthorizationInput();
        }
        foreach (self::ROLES[$change->key] as $permission) {
            $this->validatePermissionScope($permission, $change->scope, $change->resourceType);
        }
    }

    public function validateCheck(PermissionCheck $check): void
    {
        if ($this->isKnownPermission($check->permission)) {
            $this->validatePermissionScope($check->permission, $check->scope, $check->resourceType);
        }
    }

    public function isKnownPermission(string $permission): bool
    {
        return array_key_exists($permission, self::PERMISSIONS);
    }

    /** @return list<string> */
    public function rolesForPermission(string $permission): array
    {
        $roles = [];
        foreach (self::ROLES as $role => $permissions) {
            if (in_array($permission, $permissions, true)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    private function validatePermissionScope(string $permission, string $scope, ?string $resourceType): void
    {
        // A global check asks for the capability across all resources of its declared type.
        if ('resource' === $scope && (null === self::PERMISSIONS[$permission] || self::PERMISSIONS[$permission] !== $resourceType)) {
            throw new InvalidAuthorizationInput();
        }
    }
}
