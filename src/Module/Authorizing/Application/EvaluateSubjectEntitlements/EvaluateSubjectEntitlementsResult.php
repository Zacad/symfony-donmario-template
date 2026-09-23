<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\EvaluateSubjectEntitlements;

final readonly class EvaluateSubjectEntitlementsResult
{
    /** @param list<EntitlementDecisionResult> $decisions */
    public function __construct(public array $decisions)
    {
    }
}
