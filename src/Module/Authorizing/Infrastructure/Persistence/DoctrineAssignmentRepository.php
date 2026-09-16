<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Infrastructure\Persistence;

use App\Module\Authorizing\Domain\Assignment;
use App\Module\Authorizing\Domain\AssignmentChange;
use App\Module\Authorizing\Domain\AssignmentChanges;
use App\Module\Authorizing\Domain\AssignmentRepository;
use App\Module\Authorizing\Domain\AuthorizationCatalog;
use App\Module\Authorizing\Domain\InvalidAuthorizationInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAssignmentRepository implements AssignmentRepository
{
    private const array SOURCES = [
        ['table' => 'authorizing_global_role_assignment', 'key' => 'role_key', 'kind' => 'role', 'scope' => 'global'],
        ['table' => 'authorizing_resource_role_assignment', 'key' => 'role_key', 'kind' => 'role', 'scope' => 'resource'],
        ['table' => 'authorizing_global_permission_grant', 'key' => 'permission_key', 'kind' => 'permission', 'scope' => 'global'],
        ['table' => 'authorizing_resource_permission_grant', 'key' => 'permission_key', 'kind' => 'permission', 'scope' => 'resource'],
    ];

    public function __construct(private EntityManagerInterface $entityManager, private AuthorizationCatalog $catalog)
    {
    }

    public function lockAccount(Uuid $accountId): void
    {
        $this->requireTransaction();
        // One namespaced 64-bit key; PostgreSQL retains this lock until the root ends.
        $this->entityManager->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
            ['authorizing.account_assignments:'.$accountId->toRfc4122()],
        )->free();
    }

    public function change(Uuid $accountId, array $changes): AssignmentChanges
    {
        $this->requireTransaction();
        $groups = [];
        foreach ($changes as $change) {
            $source = ('permission' === $change->kind ? 2 : 0) + ('resource' === $change->scope ? 1 : 0);
            $groups[$source][$change->operation][] = $change;
        }
        ksort($groups);

        $added = 0;
        $removed = 0;
        foreach ($groups as $source => $operations) {
            foreach ($operations as $operation => $items) {
                $affected = $this->writeChanges($accountId, $source, $operation, $items);
                if ('add' === $operation) {
                    $added += $affected;
                } else {
                    $removed += $affected;
                }
            }
        }

        return new AssignmentChanges($added, $removed, count($changes) - $added - $removed);
    }

    public function evaluate(array $checks, array $existingAccountIds): array
    {
        if ([] === $checks) {
            return [];
        }

        $existing = [];
        foreach ($existingAccountIds as $accountId) {
            $existing[$accountId->toRfc4122()] = true;
        }

        $values = [];
        $parameters = [];
        $edges = [];
        $edgeParameters = [];
        foreach ($checks as $ordinal => $check) {
            $this->catalog->validateCheck($check);
            $values[] = '(CAST(? AS integer), CAST(? AS uuid), CAST(? AS text), CAST(? AS text), CAST(? AS text), CAST(? AS uuid), CAST(? AS integer))';
            array_push(
                $parameters,
                $ordinal,
                $check->accountId->toRfc4122(),
                $check->permission,
                $check->scope,
                $check->resourceType,
                $check->resourceId?->toRfc4122(),
                isset($existing[$check->accountId->toRfc4122()]) && $this->catalog->isKnownPermission($check->permission) ? 1 : 0,
            );
            foreach ($this->catalog->rolesForPermission($check->permission) as $role) {
                $edges[] = '(CAST(? AS integer), CAST(? AS text))';
                array_push($edgeParameters, $ordinal, $role);
            }
        }

        $checkValues = implode(', ', $values);
        $roleValues = [] === $edges ? '(NULL::integer, NULL::text)' : implode(', ', $edges);
        $sql = <<<SQL
            WITH permission_checks (ordinal, account_id, permission_key, scope, resource_type, resource_id, eligible) AS (
                VALUES $checkValues
            ), role_edges (ordinal, role_key) AS (
                VALUES $roleValues
            )
            SELECT CASE WHEN c.eligible = 1 AND (
                EXISTS (
                    SELECT 1 FROM public.authorizing_global_role_assignment a
                    JOIN role_edges e ON e.role_key = a.role_key AND e.ordinal = c.ordinal
                    WHERE a.account_id = c.account_id
                ) OR (
                    c.scope = 'resource' AND EXISTS (
                        SELECT 1 FROM public.authorizing_resource_role_assignment a
                        JOIN role_edges e ON e.role_key = a.role_key AND e.ordinal = c.ordinal
                        WHERE a.account_id = c.account_id
                          AND a.resource_type = c.resource_type AND a.resource_id = c.resource_id
                    )
                ) OR EXISTS (
                    SELECT 1 FROM public.authorizing_global_permission_grant a
                    WHERE a.account_id = c.account_id AND a.permission_key = c.permission_key
                ) OR (
                    c.scope = 'resource' AND EXISTS (
                        SELECT 1 FROM public.authorizing_resource_permission_grant a
                        WHERE a.account_id = c.account_id AND a.permission_key = c.permission_key
                          AND a.resource_type = c.resource_type AND a.resource_id = c.resource_id
                    )
                )
            ) THEN 1 ELSE 0 END AS allowed
            FROM permission_checks c ORDER BY c.ordinal
            SQL;

        $rows = $this->entityManager->getConnection()->fetchFirstColumn($sql, [...$parameters, ...$edgeParameters]);
        if (count($rows) !== count($checks)) {
            throw new \UnexpectedValueException('Invalid stored authorization decision.');
        }
        $decisions = [];
        foreach ($rows as $row) {
            $decisions[] = match ($row) {
                1, '1' => true,
                0, '0' => false,
                default => throw new \UnexpectedValueException('Invalid stored authorization decision.'),
            };
        }

        return $decisions;
    }

    public function assignments(Uuid $accountId, int $limit, ?int $afterSource = null, ?Uuid $afterId = null): array
    {
        if ($limit < 1 || $limit > 100 || (null === $afterSource) !== (null === $afterId)
            || (null !== $afterSource && ($afterSource < 0 || $afterSource > 3))) {
            throw new InvalidAuthorizationInput();
        }

        $branches = [];
        $parameters = [];
        foreach (self::SOURCES as $source => $definition) {
            $table = $definition['table'];
            $key = $definition['key'];
            $kind = $definition['kind'];
            $scope = $definition['scope'];
            $coordinates = 'resource' === $scope ? 'resource_type, resource_id' : 'NULL::varchar AS resource_type, NULL::uuid AS resource_id';
            $cursor = '';
            $parameters[] = $accountId->toRfc4122();
            if (null !== $afterSource && $source < $afterSource) {
                $cursor = ' AND FALSE';
            } elseif ($source === $afterSource && null !== $afterId) {
                $cursor = ' AND id > CAST(? AS uuid)';
                $parameters[] = $afterId->toRfc4122();
            }
            $parameters[] = $limit + 1;
            $branches[] = <<<SQL
                (SELECT $source AS source, id, '$kind' AS kind, $key AS assignment_key, '$scope' AS scope, $coordinates
                 FROM public.$table WHERE account_id = CAST(? AS uuid)$cursor
                 ORDER BY id LIMIT CAST(? AS integer))
                SQL;
        }

        $parameters[] = $limit + 1;
        $sql = 'SELECT * FROM ('.implode(' UNION ALL ', $branches).') assignments ORDER BY source, id LIMIT CAST(? AS integer)';
        $rows = $this->entityManager->getConnection()->fetchAllAssociative($sql, $parameters);

        return array_map($this->assignment(...), $rows);
    }

    /** @param list<AssignmentChange> $changes */
    private function writeChanges(Uuid $accountId, int $source, string $operation, array $changes): int
    {
        $definition = self::SOURCES[$source];
        $resource = 'resource' === $definition['scope'];
        $table = $definition['table'];
        $columns = 'account_id, '.$definition['key'].($resource ? ', resource_type, resource_id' : '');
        $values = [];
        $parameters = [];
        foreach ($changes as $change) {
            $placeholders = [];
            if ('add' === $operation) {
                $placeholders[] = 'CAST(? AS uuid)';
                $parameters[] = Uuid::v7()->toRfc4122();
            }
            array_push($placeholders, 'CAST(? AS uuid)', 'CAST(? AS text)');
            array_push($parameters, $accountId->toRfc4122(), $change->key);
            if ($resource) {
                array_push($placeholders, 'CAST(? AS text)', 'CAST(? AS uuid)');
                array_push($parameters, $change->resourceType, $change->resourceId?->toRfc4122());
            }
            $values[] = '('.implode(', ', $placeholders).')';
        }

        $tuples = implode(', ', $values);
        $sql = 'add' === $operation
            ? "INSERT INTO public.$table (id, $columns) VALUES $tuples ON CONFLICT ($columns) DO NOTHING"
            : "DELETE FROM public.$table WHERE ($columns) IN ($tuples)";

        return (int) $this->entityManager->getConnection()->executeStatement($sql, $parameters);
    }

    /** @param array<string, mixed> $row */
    private function assignment(array $row): Assignment
    {
        if (!is_string($row['id']) || !in_array($row['source'], [0, 1, 2, 3, '0', '1', '2', '3'], true)
            || !is_string($row['kind']) || !is_string($row['assignment_key']) || !is_string($row['scope'])
            || (null !== $row['resource_type'] && !is_string($row['resource_type']))
            || (null !== $row['resource_id'] && !is_string($row['resource_id']))) {
            throw new \UnexpectedValueException('Invalid stored authorization assignment.');
        }

        return new Assignment(
            Uuid::fromString($row['id']),
            (int) $row['source'],
            $row['kind'],
            $row['assignment_key'],
            $row['scope'],
            $row['resource_type'],
            null === $row['resource_id'] ? null : Uuid::fromString($row['resource_id']),
        );
    }

    private function requireTransaction(): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Authorization assignment changes require an active transaction.');
        }
    }
}
