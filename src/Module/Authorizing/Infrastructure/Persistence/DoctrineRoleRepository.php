<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Infrastructure\Persistence;

use App\Module\Authorizing\Domain\AuthorizationSyntaxValidator;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use App\Module\Authorizing\Domain\Role\RoleEntity;
use App\Module\Authorizing\Domain\Role\RolePermissionSetValueObject;
use App\Module\Authorizing\Domain\Role\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRoleRepository implements RoleRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function define(string $key, string $label, RolePermissionSetValueObject $permissions, ?int $expectedRevision, bool $createIfAbsent): RoleEntity
    {
        $this->lockCatalog();
        $existing = $this->get($key);
        if (null === $existing) {
            $role = RoleEntity::create($key, $label, $permissions, $expectedRevision);
            $this->assertCapacity($permissions);
            $this->entityManager->getConnection()->insert('public.authorizing_role', [
                'role_key' => $role->key(),
                'label' => $role->label(),
                'revision' => $role->revision(),
            ]);
            $this->replacePermissions($role->key(), $role->permissions());

            return $role;
        }

        $previousRevision = $existing->revision();
        if (!$existing->define($label, $permissions, $expectedRevision, $createIfAbsent)) {
            return $existing;
        }

        $this->assertCapacity($permissions, $existing->key());
        $affected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE public.authorizing_role SET label = ?, revision = ? WHERE role_key = ? AND revision = ? AND retired_at IS NULL',
            [$existing->label(), $existing->revision(), $existing->key(), $previousRevision],
        );
        if (1 !== $affected) {
            throw new InvalidAuthorizationInputException();
        }
        $this->replacePermissions($existing->key(), $existing->permissions());

        return $existing;
    }

    public function retire(string $key, int $expectedRevision): RoleEntity
    {
        $this->lockCatalog();
        $existing = $this->get($key);
        if (null === $existing) {
            throw new InvalidAuthorizationInputException();
        }

        $previousRevision = $existing->revision();
        if (null !== $existing->retiredAt()) {
            $existing->retire($expectedRevision, $existing->retiredAt());

            return $existing;
        }

        $retiredAt = $this->entityManager->getConnection()->fetchOne(
            "SELECT date_trunc('second', CURRENT_TIMESTAMP AT TIME ZONE 'UTC')::text",
        );
        if (!is_string($retiredAt)) {
            throw new InvalidAuthorizationInputException();
        }
        $existing->retire($expectedRevision, self::date($retiredAt));
        $affected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE public.authorizing_role SET retired_at = CAST(? AS timestamp without time zone), revision = ? WHERE role_key = ? AND revision = ? AND retired_at IS NULL',
            [$retiredAt, $existing->revision(), $existing->key(), $previousRevision],
        );
        if (1 !== $affected) {
            throw new InvalidAuthorizationInputException();
        }

        return $existing;
    }

    public function get(string $key): ?RoleEntity
    {
        AuthorizationSyntaxValidator::key($key);
        $row = $this->entityManager->getConnection()->fetchAssociative(
            <<<'SQL'
                SELECT r.role_key, r.label, r.revision, r.retired_at,
                       COALESCE(json_agg(m.permission_key ORDER BY m.permission_key) FILTER (WHERE m.permission_key IS NOT NULL), '[]')::text AS permissions
                FROM public.authorizing_role r
                LEFT JOIN public.authorizing_role_permission m ON m.role_key = r.role_key
                WHERE r.role_key = ?
                GROUP BY r.role_key, r.label, r.revision, r.retired_at
                SQL,
            [$key],
        );

        return false === $row ? null : self::reconstitute($row);
    }

    public function roles(int $limit, ?string $afterRoleKey = null): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidAuthorizationInputException();
        }
        if (null !== $afterRoleKey) {
            AuthorizationSyntaxValidator::key($afterRoleKey);
        }

        $cursor = null === $afterRoleKey ? '' : 'WHERE r.role_key > ?';
        $parameters = null === $afterRoleKey ? [$limit + 1] : [$afterRoleKey, $limit + 1];
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<SQL
                SELECT r.role_key, r.label, r.revision, r.retired_at,
                       COALESCE(json_agg(m.permission_key ORDER BY m.permission_key) FILTER (WHERE m.permission_key IS NOT NULL), '[]')::text AS permissions
                FROM public.authorizing_role r
                LEFT JOIN public.authorizing_role_permission m ON m.role_key = r.role_key
                $cursor
                GROUP BY r.role_key, r.label, r.revision, r.retired_at
                ORDER BY r.role_key
                LIMIT CAST(? AS integer)
                SQL,
            $parameters,
        );

        return array_map(self::reconstitute(...), $rows);
    }

    /** @param array<string, mixed> $row */
    public static function reconstitute(array $row): RoleEntity
    {
        $key = $row['role_key'] ?? null;
        $label = $row['label'] ?? null;
        $revision = $row['revision'] ?? null;
        $retiredAt = $row['retired_at'] ?? null;
        $encodedPermissions = $row['permissions'] ?? null;
        if (!is_string($key) || !is_string($label) || !is_numeric($revision)
            || (null !== $retiredAt && !is_string($retiredAt)) || !is_string($encodedPermissions)) {
            throw new \UnexpectedValueException('Invalid stored authorization role.');
        }
        try {
            $permissions = json_decode($encodedPermissions, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \UnexpectedValueException('Invalid stored authorization role.');
        }
        if (!is_array($permissions) || !array_is_list($permissions)) {
            throw new \UnexpectedValueException('Invalid stored authorization role.');
        }
        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                throw new \UnexpectedValueException('Invalid stored authorization role.');
            }
        }

        return RoleEntity::reconstitute(
            $key,
            $label,
            (int) $revision,
            null === $retiredAt ? null : self::date($retiredAt),
            $permissions,
        );
    }

    private function assertCapacity(RolePermissionSetValueObject $permissions, ?string $replacingRole = null): void
    {
        $replacement = '';
        $parameters = [];
        if (null !== $replacingRole) {
            $replacement = ' AND r.role_key <> CAST(? AS text)';
            $parameters[] = $replacingRole;
        }
        $activeEdges = $this->storedCount($this->entityManager->getConnection()->fetchOne(
            'SELECT count(*) FROM public.authorizing_role_permission m JOIN public.authorizing_role r ON r.role_key = m.role_key WHERE r.retired_at IS NULL'.$replacement,
            $parameters,
        ));
        if ($activeEdges + count($permissions->permissions) > 4096) {
            throw new InvalidAuthorizationInputException();
        }
    }

    private function lockCatalog(): void
    {
        $this->requireTransaction();
        $this->entityManager->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
            ['authorizing.role_catalog'],
        )->free();
    }

    /** @param list<string> $permissions */
    private function replacePermissions(string $key, array $permissions): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->delete('public.authorizing_role_permission', ['role_key' => $key]);
        $values = [];
        $parameters = [];
        foreach ($permissions as $permission) {
            $values[] = '(CAST(? AS text), CAST(? AS text))';
            array_push($parameters, $key, $permission);
        }
        $connection->executeStatement('INSERT INTO public.authorizing_role_permission (role_key, permission_key) VALUES '.implode(', ', $values), $parameters);
    }

    private function requireTransaction(): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Authorization role changes require an active transaction.');
        }
    }

    private function storedCount(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !is_numeric($value)) {
            throw new \UnexpectedValueException('Invalid stored authorization count.');
        }

        return (int) $value;
    }

    private static function date(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new \UnexpectedValueException('Invalid stored authorization role.');
        }
    }
}
