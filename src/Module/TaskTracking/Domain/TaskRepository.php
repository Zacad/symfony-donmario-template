<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Domain;

use Symfony\Component\Uid\Uuid;

/** Module-internal persistence port; transaction coordination belongs to the caller. */
interface TaskRepository
{
    public function add(Task $task): void;

    public function find(Uuid $id): ?Task;

    /** Requires the caller's transaction; preserves pending state under an owning row lock. */
    public function findForCompletion(Uuid $id): ?Task;

    /** @return list<Task> Ascending UUID order, at most limit + 1 rows for lookahead. */
    public function findPage(int $limit, ?Uuid $after = null, ?Uuid $ownerAccountId = null): array;
}
