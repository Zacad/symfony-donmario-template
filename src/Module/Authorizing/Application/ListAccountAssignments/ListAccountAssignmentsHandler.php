<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListAccountAssignments;

use App\Module\Authorizing\Domain\Assignment;
use App\Module\Authorizing\Domain\AssignmentRepository;
use App\Module\Authorizing\Domain\InvalidAuthorizationInput;
use App\Platform\Authorization\AuthorizeWith;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
#[AuthorizeWith(ListAccountAssignmentsPolicy::class)]
final readonly class ListAccountAssignmentsHandler
{
    public function __construct(private AssignmentRepository $assignments)
    {
    }

    public function __invoke(ListAccountAssignmentsQuery $query): ListAccountAssignmentsResult
    {
        if (null !== $query->after && (!$query->accountId->equals($query->after->accountId) || $query->after->source < 0 || $query->after->source > 3)) {
            throw new InvalidAuthorizationInput();
        }

        $rows = $this->assignments->assignments($query->accountId, $query->limit, $query->after?->source, $query->after?->assignmentId);
        $hasMore = count($rows) > $query->limit;
        $page = array_slice($rows, 0, $query->limit);
        $next = null;
        if ($hasMore && [] !== $page) {
            $last = $page[array_key_last($page)];
            $next = new AssignmentCursorResult($query->accountId, $last->source, $last->id);
        }

        return new ListAccountAssignmentsResult(array_map(static fn (Assignment $assignment): AccountAssignmentResult => new AccountAssignmentResult(
            $assignment->id,
            $assignment->source,
            $assignment->kind,
            $assignment->key,
            $assignment->scope,
            $assignment->resourceType,
            $assignment->resourceId,
        ), $page), $next);
    }
}
