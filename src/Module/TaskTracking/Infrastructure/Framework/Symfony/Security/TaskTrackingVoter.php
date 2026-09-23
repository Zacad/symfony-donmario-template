<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Infrastructure\Framework\Symfony\Security;

use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceQuery;
use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceResult;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EntitlementCheckInput;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsQuery;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsResult;
use App\Module\TaskTracking\Application\CompleteTask\CompleteTaskCommand;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\GetTask\GetTaskQuery;
use App\Module\TaskTracking\Application\ListTasks\ListTasksQuery;
use App\Module\TaskTracking\Domain\TaskPermission;
use App\Module\TaskTracking\Domain\TaskRepository;
use App\Platform\Authorization\Actor;
use App\Platform\Authorization\AuthorizationToken;
use App\Platform\Messaging\QueryBus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/** @extends Voter<string, object> */
final class TaskTrackingVoter extends Voter
{
    /** @param array<class-string, ?string> $permissions */
    public function __construct(private readonly array $permissions, private readonly QueryBus $queries, private readonly TaskRepository $tasks)
    {
    }

    public function supportsAttribute(string $attribute): bool
    {
        return self::class === $attribute;
    }

    public function supportsType(string $subjectType): bool
    {
        return array_key_exists($subjectType, $this->permissions);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::class === $attribute && is_object($subject) && array_key_exists($subject::class, $this->permissions);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$token instanceof AuthorizationToken) {
            return false;
        }
        $actor = $token->actor;
        if ($subject instanceof CreateTaskCommand) {
            if (TaskPermission::Create->value !== ($this->permissions[$subject::class] ?? null)) {
                return false;
            }
            if ($actor->isOperator('tasks')) {
                return null === $subject->ownerAccountId || $this->accountExists($subject->ownerAccountId);
            }
            if (!$actor->isAccount() || null === $actor->accountId || null === $subject->ownerAccountId
                || !$actor->accountId->equals($subject->ownerAccountId) || !$this->accountExists($actor->accountId)) {
                return false;
            }

            return $this->hasPermission($actor->accountId, TaskPermission::Create->value);
        }
        if ($subject instanceof ListTasksQuery) {
            if (TaskPermission::View->value !== ($this->permissions[$subject::class] ?? null)) {
                return false;
            }
            if ($actor->isOperator('tasks')) {
                return true;
            }

            return $actor->isAccount() && null !== $actor->accountId && null !== $subject->ownerAccountId
                && $actor->accountId->equals($subject->ownerAccountId)
                && $this->accountExists($actor->accountId)
                && $this->hasPermission($actor->accountId, TaskPermission::View->value);
        }
        if ($subject instanceof GetTaskQuery) {
            return $this->canAccessTask($subject->id, $actor, TaskPermission::View->value, $subject::class);
        }
        if ($subject instanceof CompleteTaskCommand) {
            return $this->canAccessTask($subject->id, $actor, TaskPermission::Complete->value, $subject::class);
        }

        return false;
    }

    /** @param class-string $message */
    private function canAccessTask(string $id, Actor $actor, string $permission, string $message): bool
    {
        if ($permission !== ($this->permissions[$message] ?? null) || !Uuid::isValid($id)) {
            return false;
        }
        if ($actor->isOperator('tasks')) {
            return true;
        }
        if (!$actor->isAccount() || null === $actor->accountId
            || !$this->accountExists($actor->accountId) || !$this->hasPermission($actor->accountId, $permission)) {
            return false;
        }
        $task = $this->tasks->find(Uuid::fromString($id));

        return null !== $task && null !== $task->ownerAccountId() && $task->ownerAccountId()->equals($actor->accountId);
    }

    private function hasPermission(Uuid $accountId, string $permission): bool
    {
        $result = $this->queries->ask(new EvaluateSubjectEntitlementsQuery([
            new EntitlementCheckInput($accountId, $permission),
        ]));

        return $result instanceof EvaluateSubjectEntitlementsResult && 1 === count($result->decisions) && $result->decisions[0]->allowed;
    }

    private function accountExists(Uuid $accountId): bool
    {
        $result = $this->queries->ask(new CheckAccountExistenceQuery([$accountId]));

        return $result instanceof CheckAccountExistenceResult && 1 === count($result->accounts)
            && $accountId->equals($result->accounts[0]->accountId) && $result->accounts[0]->exists;
    }
}
