<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Authorizing;

use App\Module\Authorizing\Domain\Capability\AuthorizationCatalogService;

final class AuthorizationCatalogFixture
{
    public static function create(): AuthorizationCatalogService
    {
        return new AuthorizationCatalogService(
            [
                ['module' => 'authorizing', 'key' => 'authorizing.catalogue.manage', 'label' => 'Manage authorization catalogue', 'access' => 'write', 'operations' => ['DefineRoleCommand', 'GetRoleQuery', 'ListAuthorizationCapabilitiesQuery', 'ListRolesQuery', 'RetireRoleCommand']],
                ['module' => 'authorizing', 'key' => 'authorizing.manage', 'label' => 'Manage authorization assignments', 'access' => 'write', 'operations' => ['ChangeSubjectAssignmentsCommand', 'ListSubjectAssignmentsQuery']],
                ['module' => 'task_tracking', 'key' => 'task_tracking.task.complete', 'label' => 'Complete tasks', 'access' => 'write', 'operations' => ['CompleteTaskCommand']],
                ['module' => 'task_tracking', 'key' => 'task_tracking.task.create', 'label' => 'Create tasks', 'access' => 'write', 'operations' => ['CreateTaskCommand']],
                ['module' => 'task_tracking', 'key' => 'task_tracking.task.view', 'label' => 'View tasks', 'access' => 'read', 'operations' => ['GetTaskQuery', 'ListTasksQuery']],
            ],
        );
    }
}
