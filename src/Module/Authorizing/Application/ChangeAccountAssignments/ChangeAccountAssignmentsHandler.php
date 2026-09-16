<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ChangeAccountAssignments;

use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceQuery;
use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceResult;
use App\Module\Authorizing\Domain\AssignmentChange;
use App\Module\Authorizing\Domain\AssignmentRepository;
use App\Module\Authorizing\Domain\AuthorizationCatalog;
use App\Module\Authorizing\Domain\InvalidAuthorizationInput;
use App\Platform\Authorization\AuthorizeWith;
use App\Platform\Messaging\QueryBus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
#[AuthorizeWith(ChangeAccountAssignmentsPolicy::class)]
final readonly class ChangeAccountAssignmentsHandler
{
    public function __construct(private AssignmentRepository $assignments, private AuthorizationCatalog $catalog, private QueryBus $queries)
    {
    }

    public function __invoke(ChangeAccountAssignmentsCommand $command): ChangeAccountAssignmentsResult
    {
        $changes = [];
        $seen = [];
        $hasAdditions = false;
        foreach ($command->changes as $input) {
            $change = new AssignmentChange($input->operation, $input->kind, $input->key, $input->scope, $input->resourceType, $input->resourceId);
            $this->catalog->validateChange($change);
            $key = implode('|', [$change->kind, $change->key, $change->scope, $change->resourceType ?? '', $change->resourceId?->toRfc4122() ?? '']);
            if (isset($seen[$key])) {
                throw new InvalidAuthorizationInput();
            }
            $seen[$key] = true;
            $hasAdditions = $hasAdditions || 'add' === $change->operation;
            $changes[] = $change;
        }

        if ($hasAdditions) {
            $existence = $this->queries->ask(new CheckAccountExistenceQuery([$command->accountId]));
            if (!$existence instanceof CheckAccountExistenceResult || 1 !== count($existence->accounts) || !$command->accountId->equals($existence->accounts[0]->accountId)) {
                throw new \UnexpectedValueException('authorizing.account: Invalid existence result.');
            }
            if (!$existence->accounts[0]->exists) {
                throw new \DomainException('authorizing.account: Account does not exist.');
            }
        }

        $this->assignments->lockAccount($command->accountId);
        $result = $this->assignments->change($command->accountId, $changes);

        return new ChangeAccountAssignmentsResult(count($changes), $result->added, $result->removed, $result->unchanged);
    }
}
