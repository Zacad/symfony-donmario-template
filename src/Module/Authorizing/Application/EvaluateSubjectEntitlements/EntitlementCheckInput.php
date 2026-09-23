<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\EvaluateSubjectEntitlements;

use Symfony\Component\Uid\Uuid;

final readonly class EntitlementCheckInput
{
    public function __construct(
        public Uuid $subjectId,
        public string $permission,
    ) {
    }
}
