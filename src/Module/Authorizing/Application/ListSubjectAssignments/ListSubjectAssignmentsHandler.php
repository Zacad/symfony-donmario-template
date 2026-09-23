<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListSubjectAssignments;

use App\Module\Authorizing\Domain\Assignment\AssignmentKindEnum;
use App\Module\Authorizing\Domain\Assignment\AssignmentReferenceValueObject;
use App\Module\Authorizing\Domain\Assignment\AssignmentRepository;
use App\Module\Authorizing\Domain\Capability\AuthorizingPermissionEnum;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use App\Module\Authorizing\Infrastructure\Framework\Symfony\Security\AuthorizingVoter;
use App\Platform\Authorization\Authorize;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
#[Authorize(voter: AuthorizingVoter::class, permission: AuthorizingPermissionEnum::Manage, label: 'Manage authorization assignments')]
final readonly class ListSubjectAssignmentsHandler
{
    public function __construct(private AssignmentRepository $assignments)
    {
    }

    public function __invoke(ListSubjectAssignmentsQuery $query): ListSubjectAssignmentsResult
    {
        if ($query->limit < 1 || $query->limit > 100) {
            throw new InvalidAuthorizationInputException();
        }

        $after = null;
        if (null !== $query->after) {
            $kind = AssignmentKindEnum::tryFrom($query->after->kind);
            if (!$query->subjectId->equals($query->after->subjectId) || null === $kind) {
                throw new InvalidAuthorizationInputException();
            }
            $after = new AssignmentReferenceValueObject($kind, $query->after->key);
        }

        $rows = $this->assignments->findPage($query->subjectId, $query->limit, $after);
        $hasMore = count($rows) > $query->limit;
        $page = array_slice($rows, 0, $query->limit);
        $next = null;
        if ($hasMore && [] !== $page) {
            $last = $page[array_key_last($page)];
            $next = new AssignmentCursorResult($query->subjectId, $last->kind->value, $last->key);
        }

        return new ListSubjectAssignmentsResult(array_map(static fn (AssignmentReferenceValueObject $assignment): SubjectAssignmentResult => new SubjectAssignmentResult(
            $assignment->kind->value,
            $assignment->key,
        ), $page), $next);
    }
}
