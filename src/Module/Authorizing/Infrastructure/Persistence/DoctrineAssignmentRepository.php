<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Infrastructure\Persistence;

use App\Module\Authorizing\Domain\Assignment\AssignmentChangeCountsValueObject;
use App\Module\Authorizing\Domain\Assignment\AssignmentChangeValueObject;
use App\Module\Authorizing\Domain\Assignment\AssignmentKindEnum;
use App\Module\Authorizing\Domain\Assignment\AssignmentOperationEnum;
use App\Module\Authorizing\Domain\Assignment\AssignmentReferenceValueObject;
use App\Module\Authorizing\Domain\Assignment\AssignmentRepository;
use App\Module\Authorizing\Domain\Assignment\EntitlementCheckValueObject;
use App\Module\Authorizing\Domain\Assignment\PermissionGrantEntity;
use App\Module\Authorizing\Domain\Assignment\RoleAssignmentEntity;
use App\Module\Authorizing\Domain\Capability\AuthorizationCatalogService;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use App\Module\Authorizing\Domain\Role\RoleEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAssignmentRepository implements AssignmentRepository
{
    public function __construct(private EntityManagerInterface $entityManager, private AuthorizationCatalogService $catalog)
    {
    }

    public function change(Uuid $subjectId, AssignmentChangeValueObject ...$changes): AssignmentChangeCountsValueObject
    {
        $this->requireTransaction();
        $this->lockSubject($subjectId);
        $roles = $this->activeRolesForAdditions(...$changes);

        /** @var array<string, array<string, list<RoleAssignmentEntity|PermissionGrantEntity|AssignmentReferenceValueObject>>> $groups */
        $groups = [];
        foreach ($changes as $change) {
            $reference = $change->reference;
            $item = $reference;
            if (AssignmentOperationEnum::Add === $change->operation) {
                $item = match ($reference->kind) {
                    AssignmentKindEnum::Role => RoleAssignmentEntity::assign($subjectId, $roles[$reference->key]),
                    AssignmentKindEnum::Permission => new PermissionGrantEntity($subjectId, $reference->key),
                };
            }
            $groups[$reference->kind->value][$change->operation->value][] = $item;
        }

        $added = 0;
        $removed = 0;
        foreach ([AssignmentKindEnum::Role, AssignmentKindEnum::Permission] as $kind) {
            foreach ([AssignmentOperationEnum::Remove, AssignmentOperationEnum::Add] as $operation) {
                $items = $groups[$kind->value][$operation->value] ?? [];
                if ([] === $items) {
                    continue;
                }
                $affected = $this->writeChanges($subjectId, $kind, $operation, $items);
                if (AssignmentOperationEnum::Add === $operation) {
                    $added += $affected;
                } else {
                    $removed += $affected;
                }
            }
        }

        return new AssignmentChangeCountsValueObject($added, $removed);
    }

    public function findPage(Uuid $subjectId, int $limit, ?AssignmentReferenceValueObject $after = null): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidAuthorizationInputException();
        }

        $parameters = [$subjectId->toRfc4122(), $subjectId->toRfc4122()];
        $cursor = '';
        if (null !== $after) {
            $cursor = 'WHERE (kind_order, assignment_key) > (CAST(? AS integer), CAST(? AS text))';
            array_push($parameters, self::kindOrder($after->kind), $after->key);
        }
        $parameters[] = $limit + 1;
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<SQL
                WITH assignments (kind_order, assignment_kind, assignment_key) AS (
                    SELECT 0, 'role', role_key
                    FROM public.authorizing_role_assignment
                    WHERE subject_id = CAST(? AS uuid)
                    UNION ALL
                    SELECT 1, 'permission', permission_key
                    FROM public.authorizing_permission_grant
                    WHERE subject_id = CAST(? AS uuid)
                )
                SELECT assignment_kind, assignment_key
                FROM assignments
                $cursor
                ORDER BY kind_order, assignment_key
                LIMIT CAST(? AS integer)
                SQL,
            $parameters,
        );

        return array_map(static function (array $row): AssignmentReferenceValueObject {
            if (!is_string($row['assignment_kind'] ?? null) || !is_string($row['assignment_key'] ?? null)) {
                throw new \UnexpectedValueException('Invalid stored authorization assignment.');
            }

            try {
                return new AssignmentReferenceValueObject(AssignmentKindEnum::from($row['assignment_kind']), $row['assignment_key']);
            } catch (\ValueError|InvalidAuthorizationInputException) {
                throw new \UnexpectedValueException('Invalid stored authorization assignment.');
            }
        }, $rows);
    }

    public function evaluate(EntitlementCheckValueObject ...$checks): array
    {
        if ([] === $checks) {
            return [];
        }

        $values = [];
        $parameters = [];
        foreach ($checks as $ordinal => $check) {
            $values[] = '(CAST(? AS integer), CAST(? AS uuid), CAST(? AS text), CAST(? AS integer))';
            array_push(
                $parameters,
                $ordinal,
                $check->subjectId->toRfc4122(),
                $check->permission,
                $this->catalog->isKnownPermission($check->permission) ? 1 : 0,
            );
        }

        $checkValues = implode(', ', $values);
        $rows = $this->entityManager->getConnection()->fetchFirstColumn(
            <<<SQL
                WITH permission_checks (ordinal, subject_id, permission_key, eligible) AS (
                    VALUES $checkValues
                )
                SELECT CASE WHEN c.eligible = 1 AND (
                    EXISTS (
                        SELECT 1
                        FROM public.authorizing_role_assignment a
                        JOIN public.authorizing_role r ON r.role_key = a.role_key AND r.retired_at IS NULL
                        JOIN public.authorizing_role_permission m ON m.role_key = r.role_key AND m.permission_key = c.permission_key
                        WHERE a.subject_id = c.subject_id
                    ) OR EXISTS (
                        SELECT 1
                        FROM public.authorizing_permission_grant g
                        WHERE g.subject_id = c.subject_id AND g.permission_key = c.permission_key
                    )
                ) THEN 1 ELSE 0 END AS allowed
                FROM permission_checks c
                ORDER BY c.ordinal
                SQL,
            $parameters,
        );
        if (count($rows) !== count($checks)) {
            throw new \UnexpectedValueException('Invalid stored authorization decision.');
        }

        return array_map(static fn (mixed $row): bool => match ($row) {
            1, '1' => true,
            0, '0' => false,
            default => throw new \UnexpectedValueException('Invalid stored authorization decision.'),
        }, $rows);
    }

    private function lockSubject(Uuid $subjectId): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
            ['authorizing.subject_assignments:'.$subjectId->toRfc4122()],
        )->free();
    }

    /** @return array<string, RoleEntity> */
    private function activeRolesForAdditions(AssignmentChangeValueObject ...$changes): array
    {
        $keys = [];
        foreach ($changes as $change) {
            if (AssignmentOperationEnum::Add === $change->operation && AssignmentKindEnum::Role === $change->reference->kind) {
                $keys[$change->reference->key] = true;
            }
        }
        if ([] === $keys) {
            return [];
        }

        $this->entityManager->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock_shared(hashtextextended(CAST(? AS text), 0))',
            ['authorizing.role_catalog'],
        )->free();

        $values = [];
        $parameters = [];
        foreach (array_keys($keys) as $key) {
            $values[] = '(CAST(? AS text))';
            $parameters[] = $key;
        }
        $requested = implode(', ', $values);
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<SQL
                WITH requested (role_key) AS (VALUES $requested)
                SELECT r.role_key, r.label, r.revision, r.retired_at,
                       COALESCE(json_agg(m.permission_key ORDER BY m.permission_key) FILTER (WHERE m.permission_key IS NOT NULL), '[]')::text AS permissions
                FROM requested q
                JOIN public.authorizing_role r ON r.role_key = q.role_key AND r.retired_at IS NULL
                LEFT JOIN public.authorizing_role_permission m ON m.role_key = r.role_key
                GROUP BY r.role_key, r.label, r.revision, r.retired_at
                ORDER BY r.role_key
                SQL,
            $parameters,
        );

        $roles = [];
        foreach ($rows as $row) {
            $role = DoctrineRoleRepository::reconstitute($row);
            $roles[$role->key()] = $role;
        }
        if (count($roles) !== count($keys)) {
            throw new InvalidAuthorizationInputException();
        }

        return $roles;
    }

    /**
     * @param list<RoleAssignmentEntity|PermissionGrantEntity|AssignmentReferenceValueObject> $items
     */
    private function writeChanges(Uuid $subjectId, AssignmentKindEnum $kind, AssignmentOperationEnum $operation, array $items): int
    {
        $table = AssignmentKindEnum::Role === $kind ? 'authorizing_role_assignment' : 'authorizing_permission_grant';
        $keyColumn = AssignmentKindEnum::Role === $kind ? 'role_key' : 'permission_key';
        $values = [];
        $parameters = [];
        foreach ($items as $item) {
            $values[] = '(CAST(? AS uuid), CAST(? AS text))';
            if ($item instanceof RoleAssignmentEntity) {
                array_push($parameters, $item->subjectId()->toRfc4122(), $item->role()->key());
            } elseif ($item instanceof PermissionGrantEntity) {
                array_push($parameters, $item->subjectId()->toRfc4122(), $item->permissionKey());
            } else {
                array_push($parameters, $subjectId->toRfc4122(), $item->key);
            }
        }
        $tuples = implode(', ', $values);
        $sql = AssignmentOperationEnum::Add === $operation
            ? "INSERT INTO public.$table (subject_id, $keyColumn) VALUES $tuples ON CONFLICT (subject_id, $keyColumn) DO NOTHING"
            : "DELETE FROM public.$table WHERE (subject_id, $keyColumn) IN ($tuples)";

        return (int) $this->entityManager->getConnection()->executeStatement($sql, $parameters);
    }

    private static function kindOrder(AssignmentKindEnum $kind): int
    {
        return AssignmentKindEnum::Role === $kind ? 0 : 1;
    }

    private function requireTransaction(): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Authorization assignment changes require an active transaction.');
        }
    }
}
