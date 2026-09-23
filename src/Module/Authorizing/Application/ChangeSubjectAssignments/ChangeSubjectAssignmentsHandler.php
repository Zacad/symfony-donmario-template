<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ChangeSubjectAssignments;

use App\Module\Authorizing\Domain\Assignment\AssignmentChangeValueObject;
use App\Module\Authorizing\Domain\Assignment\AssignmentKindEnum;
use App\Module\Authorizing\Domain\Assignment\AssignmentOperationEnum;
use App\Module\Authorizing\Domain\Assignment\AssignmentReferenceValueObject;
use App\Module\Authorizing\Domain\Assignment\AssignmentRepository;
use App\Module\Authorizing\Domain\Capability\AuthorizationCatalogService;
use App\Module\Authorizing\Domain\Capability\AuthorizingPermissionEnum;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use App\Module\Authorizing\Infrastructure\Framework\Symfony\Security\AuthorizingVoter;
use App\Platform\Authorization\Authorize;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
#[Authorize(voter: AuthorizingVoter::class, permission: AuthorizingPermissionEnum::Manage, label: 'Manage authorization assignments')]
final readonly class ChangeSubjectAssignmentsHandler
{
    public function __construct(private AssignmentRepository $assignments, private AuthorizationCatalogService $catalog)
    {
    }

    public function __invoke(ChangeSubjectAssignmentsCommand $command): ChangeSubjectAssignmentsResult
    {
        $changes = [];
        $seen = [];
        $additions = 0;
        $removals = 0;
        foreach ($command->changes as $input) {
            $operation = AssignmentOperationEnum::tryFrom($input->operation);
            $kind = AssignmentKindEnum::tryFrom($input->kind);
            if (null === $operation || null === $kind) {
                throw new InvalidAuthorizationInputException();
            }

            $reference = new AssignmentReferenceValueObject($kind, $input->key);
            $key = $kind->value."\0".$input->key;
            if (isset($seen[$key])) {
                throw new InvalidAuthorizationInputException();
            }
            $seen[$key] = true;

            $change = new AssignmentChangeValueObject($operation, $reference);
            if (AssignmentOperationEnum::Add === $operation) {
                $this->catalog->validateChange($change);
                ++$additions;
            } else {
                ++$removals;
            }
            $changes[] = $change;
        }

        $result = $this->assignments->change($command->subjectId, ...$changes);
        $requested = count($changes);
        if ($result->added < 0 || $result->removed < 0
            || $result->added > $additions || $result->removed > $removals
            || $result->added + $result->removed > $requested) {
            throw new \UnexpectedValueException('authorizing.assignments: Invalid change counts.');
        }
        $unchanged = $requested - $result->added - $result->removed;

        return new ChangeSubjectAssignmentsResult($requested, $result->added, $result->removed, $unchanged);
    }
}
