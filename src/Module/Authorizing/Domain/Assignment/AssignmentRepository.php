<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Assignment;

use Symfony\Component\Uid\Uuid;

interface AssignmentRepository
{
    public function change(Uuid $subjectId, AssignmentChangeValueObject ...$changes): AssignmentChangeCountsValueObject;

    /** @return list<AssignmentReferenceValueObject> Up to limit + 1 rows, including lookahead. */
    public function findPage(Uuid $subjectId, int $limit, ?AssignmentReferenceValueObject $after = null): array;

    /** @return list<bool> */
    public function evaluate(EntitlementCheckValueObject ...$checks): array;
}
