<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authorizing\Domain\AssignmentChange;
use App\Module\Authorizing\Domain\AuthorizationCatalog;
use App\Module\Authorizing\Domain\InvalidAuthorizationInput;
use App\Module\Authorizing\Domain\PermissionCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AuthorizingDomainTest extends TestCase
{
    /** @param list<string> $roles */
    #[DataProvider('permissionBundles')]
    public function testCatalogueHasOnlyExplicitFlatRoleBundles(string $permission, array $roles): void
    {
        $catalog = new AuthorizationCatalog();

        self::assertTrue($catalog->isKnownPermission($permission));
        self::assertSame($roles, $catalog->rolesForPermission($permission));
        $catalog->validateCheck(new PermissionCheck(Uuid::v7(), $permission, 'global'));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function permissionBundles(): iterable
    {
        yield 'create is global-only' => ['task_tracking.task.create', ['task_tracking.creator']];
        yield 'view is additive reader and editor' => ['task_tracking.task.view', ['task_tracking.reader', 'task_tracking.editor']];
        yield 'complete has no reader or administrator bypass' => ['task_tracking.task.complete', ['task_tracking.editor']];
        yield 'administration has no task role' => ['authorizing.manage', ['authorizing.administrator']];
    }

    public function testUnknownPermissionHasNoRoleFallbackAndIsNotAnInvalidCheck(): void
    {
        $catalog = new AuthorizationCatalog();
        foreach (['task_tracking.task', 'task_tracking.task.future', 'authorizing.administrator', 'retired.permission'] as $permission) {
            self::assertFalse($catalog->isKnownPermission($permission));
            self::assertSame([], $catalog->rolesForPermission($permission));
            $catalog->validateCheck(new PermissionCheck(Uuid::v7(), $permission, 'global'));
            $catalog->validateCheck(new PermissionCheck(Uuid::v7(), $permission, 'resource', 'retired.type', Uuid::v7()));
        }
    }

    #[DataProvider('supportedAssignments')]
    public function testAdditionsSupportOnlyDeclaredScopes(string $kind, string $key, string $scope): void
    {
        $change = new AssignmentChange('add', $kind, $key, $scope, 'resource' === $scope ? 'task_tracking.task' : null, 'resource' === $scope ? Uuid::v7() : null);
        (new AuthorizationCatalog())->validateChange($change);

        self::assertSame($scope, $change->scope);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function supportedAssignments(): iterable
    {
        yield 'global creator' => ['role', 'task_tracking.creator', 'global'];
        yield 'global reader' => ['role', 'task_tracking.reader', 'global'];
        yield 'global editor' => ['role', 'task_tracking.editor', 'global'];
        yield 'global administrator' => ['role', 'authorizing.administrator', 'global'];
        yield 'resource reader' => ['role', 'task_tracking.reader', 'resource'];
        yield 'resource editor' => ['role', 'task_tracking.editor', 'resource'];
        yield 'global create grant' => ['permission', 'task_tracking.task.create', 'global'];
        yield 'global view grant' => ['permission', 'task_tracking.task.view', 'global'];
        yield 'global complete grant' => ['permission', 'task_tracking.task.complete', 'global'];
        yield 'global manage grant' => ['permission', 'authorizing.manage', 'global'];
        yield 'resource view grant' => ['permission', 'task_tracking.task.view', 'resource'];
        yield 'resource complete grant' => ['permission', 'task_tracking.task.complete', 'resource'];
    }

    #[DataProvider('unsupportedAssignments')]
    public function testUnknownAndIncompatibleAdditionsFail(string $kind, string $key, string $scope, ?string $resourceType): void
    {
        $change = new AssignmentChange('add', $kind, $key, $scope, $resourceType, 'resource' === $scope ? Uuid::v7() : null);
        $this->expectException(InvalidAuthorizationInput::class);
        $this->expectExceptionMessage('Invalid authorization input.');

        (new AuthorizationCatalog())->validateChange($change);
    }

    #[DataProvider('unsupportedAssignments')]
    public function testRetiredOrReScopedAssignmentsRemainRemovable(string $kind, string $key, string $scope, ?string $resourceType): void
    {
        $change = new AssignmentChange('remove', $kind, $key, $scope, $resourceType, 'resource' === $scope ? Uuid::v7() : null);
        (new AuthorizationCatalog())->validateChange($change);

        self::assertSame('remove', $change->operation);
    }

    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function unsupportedAssignments(): iterable
    {
        yield 'unknown global role' => ['role', 'retired.role', 'global', null];
        yield 'unknown resource role and type' => ['role', 'retired.role', 'resource', 'retired.type'];
        yield 'unknown global grant' => ['permission', 'retired.permission', 'global', null];
        yield 'unknown resource grant and type' => ['permission', 'retired.permission', 'resource', 'retired.type'];
        yield 'resource creator' => ['role', 'task_tracking.creator', 'resource', 'task_tracking.task'];
        yield 'resource administrator' => ['role', 'authorizing.administrator', 'resource', 'task_tracking.task'];
        yield 'resource create' => ['permission', 'task_tracking.task.create', 'resource', 'task_tracking.task'];
        yield 'resource manage' => ['permission', 'authorizing.manage', 'resource', 'task_tracking.task'];
        yield 'wrong reader type' => ['role', 'task_tracking.reader', 'resource', 'another.type'];
        yield 'wrong editor type' => ['role', 'task_tracking.editor', 'resource', 'another.type'];
        yield 'wrong grant type' => ['permission', 'task_tracking.task.view', 'resource', 'another.type'];
        yield 'permission used as role' => ['role', 'task_tracking.task.view', 'global', null];
        yield 'role used as permission' => ['permission', 'task_tracking.editor', 'global', null];
    }

    public function testResourceCapablePermissionsMayBeCheckedGloballyOrForTheirExactType(): void
    {
        $catalog = new AuthorizationCatalog();
        foreach (['task_tracking.task.view', 'task_tracking.task.complete'] as $permission) {
            $catalog->validateCheck(new PermissionCheck(Uuid::v7(), $permission, 'global'));
            $catalog->validateCheck(new PermissionCheck(Uuid::v7(), $permission, 'resource', 'task_tracking.task', Uuid::v7()));
            self::assertTrue($catalog->isKnownPermission($permission));
        }
    }

    #[DataProvider('incompatibleChecks')]
    public function testKnownPermissionIncompatibleScopeFailsInsteadOfDenying(string $permission, string $resourceType): void
    {
        $check = new PermissionCheck(Uuid::v7(), $permission, 'resource', $resourceType, Uuid::v7());
        $this->expectException(InvalidAuthorizationInput::class);

        (new AuthorizationCatalog())->validateCheck($check);
    }

    /** @return iterable<string, array{string, string}> */
    public static function incompatibleChecks(): iterable
    {
        yield 'create is global-only' => ['task_tracking.task.create', 'task_tracking.task'];
        yield 'manage is global-only' => ['authorizing.manage', 'task_tracking.task'];
        yield 'view wrong type' => ['task_tracking.task.view', 'another.type'];
        yield 'complete wrong type' => ['task_tracking.task.complete', 'another.type'];
    }

    #[DataProvider('invalidKeys')]
    public function testChangeRejectsMalformedKeysEvenForRemoval(string $key): void
    {
        $this->expectException(InvalidAuthorizationInput::class);
        $this->expectExceptionMessage('Invalid authorization input.');
        new AssignmentChange('remove', 'role', $key, 'global');
    }

    #[DataProvider('invalidKeys')]
    public function testCheckRejectsMalformedUnknownPermissions(string $key): void
    {
        $this->expectException(InvalidAuthorizationInput::class);
        new PermissionCheck(Uuid::v7(), $key, 'global');
    }

    #[DataProvider('invalidKeys')]
    public function testCheckRejectsMalformedResourceTypesEvenForUnknownPermissions(string $key): void
    {
        $this->expectException(InvalidAuthorizationInput::class);
        new PermissionCheck(Uuid::v7(), 'unknown.permission', 'resource', $key, Uuid::v7());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'uppercase' => ['Task_tracking.reader'];
        yield 'unicode' => ['tâsk.reader'];
        yield 'leading space' => [' task_tracking.reader'];
        yield 'newline suffix' => ["task_tracking.reader\n"];
        yield 'NUL' => ["task\0reader"];
        yield 'wildcard' => ['task_tracking.*'];
        yield 'colon' => ['task:reader'];
        yield 'slash' => ['task/reader'];
    }

    public function testSyntaxBoundariesRemainUsableForRetiredKeyCleanup(): void
    {
        $catalog = new AuthorizationCatalog();
        foreach (['a', 'a0.b_c-d', str_repeat('a', 64)] as $key) {
            $change = new AssignmentChange('remove', 'permission', $key, 'resource', $key, Uuid::v7());
            $catalog->validateChange($change);
            self::assertSame($key, $change->key);
        }
    }

    #[DataProvider('invalidScopes')]
    public function testScopeAndCoordinatesAreAnExactPairForChanges(string $scope, ?string $resourceType, bool $hasResourceId): void
    {
        $this->expectException(InvalidAuthorizationInput::class);
        new AssignmentChange('remove', 'role', 'retired.role', $scope, $resourceType, $hasResourceId ? Uuid::v7() : null);
    }

    #[DataProvider('invalidScopes')]
    public function testScopeAndCoordinatesAreAnExactPairForChecks(string $scope, ?string $resourceType, bool $hasResourceId): void
    {
        $this->expectException(InvalidAuthorizationInput::class);
        new PermissionCheck(Uuid::v7(), 'retired.permission', $scope, $resourceType, $hasResourceId ? Uuid::v7() : null);
    }

    /** @return iterable<string, array{string, ?string, bool}> */
    public static function invalidScopes(): iterable
    {
        yield 'unknown scope' => ['all', null, false];
        yield 'scope case sensitive' => ['Global', null, false];
        yield 'global with type' => ['global', 'task_tracking.task', false];
        yield 'global with UUID' => ['global', null, true];
        yield 'global with both' => ['global', 'task_tracking.task', true];
        yield 'resource without both' => ['resource', null, false];
        yield 'resource without type' => ['resource', null, true];
        yield 'resource without UUID' => ['resource', 'task_tracking.task', false];
        yield 'resource malformed type' => ['resource', 'UPPERCASE', true];
    }

    #[DataProvider('invalidOperationsAndKinds')]
    public function testChangeOperationAndKindAreExact(string $operation, string $kind): void
    {
        $this->expectException(InvalidAuthorizationInput::class);
        new AssignmentChange($operation, $kind, 'task_tracking.reader', 'global');
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidOperationsAndKinds(): iterable
    {
        yield 'unknown operation' => ['replace', 'role'];
        yield 'operation case' => ['ADD', 'role'];
        yield 'unknown kind' => ['add', 'grant'];
        yield 'kind case' => ['add', 'Role'];
    }
}
