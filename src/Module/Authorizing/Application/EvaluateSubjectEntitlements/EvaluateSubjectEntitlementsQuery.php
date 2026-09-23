<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\EvaluateSubjectEntitlements;

final readonly class EvaluateSubjectEntitlementsQuery
{
    /** @param list<EntitlementCheckInput> $checks */
    public function __construct(public array $checks)
    {
    }
}
