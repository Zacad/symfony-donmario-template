<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authorizing\Application\DefineRole\DefineRoleCommand;
use App\Module\Authorizing\Application\DefineRole\DefineRoleHandler;
use App\Module\Authorizing\Application\GetRole\GetRoleHandler;
use App\Module\Authorizing\Application\GetRole\GetRoleQuery;
use App\Module\Authorizing\Application\GetRole\GetRoleResult;
use App\Module\Authorizing\Application\ListAuthorizationCapabilities\AuthorizationCapabilityResult;
use App\Module\Authorizing\Application\ListAuthorizationCapabilities\ListAuthorizationCapabilitiesHandler;
use App\Module\Authorizing\Application\ListAuthorizationCapabilities\ListAuthorizationCapabilitiesQuery;
use App\Module\Authorizing\Application\ListRoles\ListRolesHandler;
use App\Module\Authorizing\Application\ListRoles\ListRolesQuery;
use App\Module\Authorizing\Application\RetireRole\RetireRoleCommand;
use App\Module\Authorizing\Domain\Capability\AuthorizationCatalogService;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use App\Module\Authorizing\Domain\Role\RoleEntity;
use App\Module\Authorizing\Domain\Role\RolePermissionSetValueObject;
use App\Module\Authorizing\Domain\Role\RoleRepository;
use App\Tests\Fixtures\Authorizing\AuthorizationCatalogFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class AuthorizingRoleApplicationTest extends TestCase
{
    public function testDefinePersistsSortedCrossModulePermissionSet(): void
    {
        $repository = $this->createMock(RoleRepository::class);
        $repository->expects(self::once())->method('define')->with(
            'operations.lead',
            'Operations lead',
            self::callback(static fn (RolePermissionSetValueObject $permissions): bool => [
                'authorizing.manage',
                'task_tracking.task.view',
            ] === $permissions->permissions),
            7,
            false,
        )->willReturn(RoleEntity::reconstitute('operations.lead', 'Operations lead', 8, null, [
            'authorizing.manage',
            'task_tracking.task.view',
        ]));

        $result = new DefineRoleHandler(AuthorizationCatalogFixture::create(), $repository)(new DefineRoleCommand(
            'operations.lead',
            'Operations lead',
            ['task_tracking.task.view', 'authorizing.manage'],
            7,
        ));

        self::assertSame(['key', 'label', 'revision', 'retiredAt', 'permissions'], array_keys(get_object_vars($result)));
        self::assertSame(8, $result->revision);
    }

    public function testUnknownOrDuplicatePermissionFailsBeforePersistence(): void
    {
        $repository = $this->createMock(RoleRepository::class);
        $repository->expects(self::never())->method('define');
        $handler = new DefineRoleHandler(AuthorizationCatalogFixture::create(), $repository);

        foreach ([['retired.permission'], ['authorizing.manage', 'authorizing.manage']] as $permissions) {
            try {
                $handler(new DefineRoleCommand('runtime.role', 'Runtime role', $permissions));
                self::fail('Invalid permission set was accepted.');
            } catch (InvalidAuthorizationInputException) {
            }
        }
    }

    public function testRoleListUsesStableLookaheadAndCleanResultShape(): void
    {
        $repository = $this->createMock(RoleRepository::class);
        $repository->expects(self::once())->method('roles')->with(2, 'role.a')->willReturn([
            RoleEntity::reconstitute('role.b', 'B', 1, null, ['authorizing.manage']),
            RoleEntity::reconstitute('role.c', 'C', 2, null, ['task_tracking.task.view']),
            RoleEntity::reconstitute('role.d', 'D', 3, null, ['task_tracking.task.complete']),
        ]);

        $result = new ListRolesHandler($repository)(new ListRolesQuery(2, 'role.a'));

        self::assertSame(['role.b', 'role.c'], array_map(static fn ($role): string => $role->key, $result->roles));
        self::assertSame('role.c', $result->nextAfterRoleKey);
        self::assertSame(['key', 'label', 'revision', 'retiredAt', 'permissions'], array_keys(get_object_vars($result->roles[0])));
    }

    public function testGetRoleMapsTheDomainSnapshotAndPreservesNotFound(): void
    {
        $retiredAt = new \DateTimeImmutable('2026-09-21 12:00:00 UTC');
        $repository = $this->createMock(RoleRepository::class);
        $repository->expects(self::exactly(2))->method('get')->willReturnMap([
            ['operations.lead', RoleEntity::reconstitute('operations.lead', 'Operations lead', 3, $retiredAt, ['authorizing.manage'])],
            ['missing.role', null],
        ]);
        $handler = new GetRoleHandler($repository);

        $result = $handler(new GetRoleQuery('operations.lead'));

        self::assertNotNull($result);
        self::assertSame(['operations.lead', 'Operations lead', 3, ['authorizing.manage']], [$result->key, $result->label, $result->revision, $result->permissions]);
        self::assertSame($retiredAt, $result->retiredAt);
        self::assertNull($handler(new GetRoleQuery('missing.role')));
    }

    public function testCapabilityListHasOnlyGlobalCapabilitySemantics(): void
    {
        $catalog = new AuthorizationCatalogService([
            ['module' => 'synthetic_module', 'key' => 'synthetic.capability.alpha', 'label' => 'Synthetic alpha', 'access' => 'read', 'operations' => ['SyntheticAlphaQuery']],
            ['module' => 'synthetic_module', 'key' => 'synthetic.capability.beta', 'label' => 'Synthetic beta', 'access' => 'write', 'operations' => ['SyntheticBetaCommand']],
        ]);

        $result = new ListAuthorizationCapabilitiesHandler($catalog)(new ListAuthorizationCapabilitiesQuery(1));

        self::assertSame('synthetic.capability.alpha', $result->capabilities[0]->key);
        self::assertSame(['module', 'key', 'label', 'access', 'operations'], array_keys(get_object_vars($result->capabilities[0])));
        self::assertSame('synthetic.capability.alpha', $result->nextAfterKey);
    }

    public function testRoleAndCapabilityValidationUseTheGlobalDtosAndTotalEdgeBound(): void
    {
        $validator = Validation::createValidatorBuilder()->addYamlMapping(dirname(__DIR__, 2).'/src/Module/Authorizing/Resources/config/validation.yaml')->getValidator();
        $permissions = array_fill(0, 33, 'task_tracking.task.view');

        self::assertCount(0, $validator->validate(new DefineRoleCommand('runtime.role', 'Runtime role', $permissions)));
        self::assertCount(0, $validator->validate(new GetRoleResult('runtime.role', 'Runtime role', 1, null, ['task_tracking.task.view'])));
        self::assertCount(0, $validator->validate(new AuthorizationCapabilityResult('synthetic_module', 'synthetic.capability', 'Synthetic capability', 'read', ['SyntheticQuery'])));
        self::assertGreaterThan(0, count($validator->validate(new DefineRoleCommand('runtime.role', 'Runtime role', array_fill(0, 4097, 'task_tracking.task.view')))));
        self::assertGreaterThan(0, count($validator->validate(new RetireRoleCommand('runtime.role', 0))));
    }
}
