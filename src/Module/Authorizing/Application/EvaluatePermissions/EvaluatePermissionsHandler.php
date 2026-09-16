<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\EvaluatePermissions;

use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceQuery;
use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceResult;
use App\Module\Authorizing\Domain\AssignmentRepository;
use App\Module\Authorizing\Domain\AuthorizationCatalog;
use App\Module\Authorizing\Domain\PermissionCheck;
use App\Platform\Authorization\AuthorizeWith;
use App\Platform\Messaging\QueryBus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
#[AuthorizeWith(EvaluatePermissionsPolicy::class)]
final readonly class EvaluatePermissionsHandler
{
    public function __construct(private AssignmentRepository $assignments, private AuthorizationCatalog $catalog, private QueryBus $queries)
    {
    }

    public function __invoke(EvaluatePermissionsQuery $query): EvaluatePermissionsResult
    {
        $checks = [];
        $accountIds = [];
        foreach ($query->checks as $input) {
            $check = new PermissionCheck($input->accountId, $input->permission, $input->scope, $input->resourceType, $input->resourceId);
            $this->catalog->validateCheck($check);
            $checks[] = $check;
            $accountIds[$check->accountId->toRfc4122()] = $check->accountId;
        }

        $existence = $this->queries->ask(new CheckAccountExistenceQuery(array_values($accountIds)));
        if (!$existence instanceof CheckAccountExistenceResult) {
            throw new \UnexpectedValueException('authorizing.account: Invalid existence result.');
        }
        $existingAccountIds = [];
        foreach ($existence->accounts as $account) {
            if ($account->exists) {
                $existingAccountIds[] = $account->accountId;
            }
        }
        $allowed = $this->assignments->evaluate($checks, $existingAccountIds);
        if (count($allowed) !== count($checks)) {
            throw new \UnexpectedValueException('authorizing.decisions: Invalid decision count.');
        }

        return new EvaluatePermissionsResult(array_map(static fn (bool $decision): PermissionDecisionResult => new PermissionDecisionResult($decision), $allowed));
    }
}
