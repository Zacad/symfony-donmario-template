<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\EvaluateSubjectEntitlements;

final readonly class EntitlementDecisionResult
{
    public function __construct(public bool $allowed)
    {
    }
}
