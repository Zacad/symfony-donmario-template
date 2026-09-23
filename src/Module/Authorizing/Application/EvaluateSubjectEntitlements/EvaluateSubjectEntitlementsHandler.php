<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\EvaluateSubjectEntitlements;

use App\Module\Authorizing\Domain\Assignment\AssignmentRepository;
use App\Module\Authorizing\Domain\Assignment\EntitlementCheckValueObject;
use App\Module\Authorizing\Infrastructure\Framework\Symfony\Security\AuthorizingVoter;
use App\Platform\Authorization\Authorize;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
#[Authorize(voter: AuthorizingVoter::class)]
final readonly class EvaluateSubjectEntitlementsHandler
{
    public function __construct(private AssignmentRepository $assignments)
    {
    }

    public function __invoke(EvaluateSubjectEntitlementsQuery $query): EvaluateSubjectEntitlementsResult
    {
        $checks = [];
        foreach ($query->checks as $input) {
            $checks[] = new EntitlementCheckValueObject($input->subjectId, $input->permission);
        }

        $allowed = $this->assignments->evaluate(...$checks);
        if (count($allowed) !== count($checks)) {
            throw new \UnexpectedValueException('authorizing.decisions: Invalid decision count.');
        }

        return new EvaluateSubjectEntitlementsResult(array_map(static fn (bool $decision): EntitlementDecisionResult => new EntitlementDecisionResult($decision), $allowed));
    }
}
