<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain;

use Symfony\Component\Uid\Uuid;

interface AssignmentRepository
{
    public function lockAccount(Uuid $accountId): void;

    /** @param list<AssignmentChange> $changes */
    public function change(Uuid $accountId, array $changes): AssignmentChanges;

    /**
     * @param list<PermissionCheck> $checks
     * @param list<Uuid>            $existingAccountIds
     *
     * @return list<bool>
     */
    public function evaluate(array $checks, array $existingAccountIds): array;

    /** @return list<Assignment> Up to limit + 1 rows, including lookahead. */
    public function assignments(Uuid $accountId, int $limit, ?int $afterSource = null, ?Uuid $afterId = null): array;
}
