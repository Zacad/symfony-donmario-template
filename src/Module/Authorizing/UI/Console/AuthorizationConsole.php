<?php

declare(strict_types=1);

namespace App\Module\Authorizing\UI\Console;

use App\Module\Authorizing\Application\ChangeAccountAssignments\AssignmentChangeInput;
use App\Module\Authorizing\Application\ChangeAccountAssignments\ChangeAccountAssignmentsCommand;
use App\Module\Authorizing\Application\ChangeAccountAssignments\ChangeAccountAssignmentsResult;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsQuery;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsResult;
use App\Module\Authorizing\Application\EvaluatePermissions\PermissionCheckInput;
use App\Module\Authorizing\Application\ListAccountAssignments\ListAccountAssignmentsQuery;
use App\Module\Authorizing\Application\ListAccountAssignments\ListAccountAssignmentsResult;
use App\Platform\Authorization\OperatorExecution;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\QueryBus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Uid\Uuid;

final readonly class AuthorizationConsole
{
    public function __construct(private CommandBus $commands, private QueryBus $queries, private AuthorizationConsoleInput $transport, private OperatorExecution $operator)
    {
    }

    /** @param callable(): array<string, mixed> $operation */
    public function run(OutputInterface $output, callable $operation): int
    {
        try {
            $output->writeln(json_encode($operation(), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        } catch (\DomainException|ValidationFailedException) {
            $output->writeln('Invalid authorization input.', OutputInterface::OUTPUT_RAW);

            return Command::INVALID;
        } catch (\Throwable) {
            $output->writeln('Authorization operation failed.', OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }
    }

    /** @return array<string, int> */
    public function change(InputInterface $input, string $operation, string $kind, string $argument): array
    {
        [$scope, $type, $id] = $this->transport->scope($input);

        return $this->dispatchChanges($this->transport->uuid($input->getArgument('account')), [
            new AssignmentChangeInput($operation, $kind, $this->transport->text($input->getArgument($argument)), $scope, $type, $id),
        ]);
    }

    /** @return array<string, int> */
    public function changeBatch(InputInterface $input): array
    {
        $accountId = $this->transport->uuid($input->getArgument('account'));
        $changes = [];
        foreach ($this->transport->batch($input) as $item) {
            $fields = $this->transport->fields($item, ['operation', 'kind', 'key', 'scope'], ['resourceType', 'resourceId']);
            [$scope, $type, $id] = $this->transport->batchScope($fields);
            $changes[] = new AssignmentChangeInput(
                $this->transport->text($fields['operation']),
                $this->transport->text($fields['kind']),
                $this->transport->text($fields['key']),
                $scope, $type, $id,
            );
        }

        return $this->dispatchChanges($accountId, $changes);
    }

    /** @return array{allowed: bool} */
    public function check(InputInterface $input): array
    {
        [$scope, $type, $id] = $this->transport->scope($input);
        $decisions = $this->evaluate([
            new PermissionCheckInput($this->transport->uuid($input->getArgument('account')), $this->transport->text($input->getArgument('permission')), $scope, $type, $id),
        ]);

        return $decisions[0];
    }

    /** @return array{decisions: list<array{allowed: bool}>} */
    public function checkBatch(InputInterface $input): array
    {
        $checks = [];
        foreach ($this->transport->batch($input) as $item) {
            $fields = $this->transport->fields($item, ['accountId', 'permission', 'scope'], ['resourceType', 'resourceId']);
            [$scope, $type, $id] = $this->transport->batchScope($fields);
            $checks[] = new PermissionCheckInput($this->transport->uuid($fields['accountId']), $this->transport->text($fields['permission']), $scope, $type, $id);
        }

        return ['decisions' => $this->evaluate($checks)];
    }

    /** @return array<string, mixed> */
    public function assignments(InputInterface $input): array
    {
        $message = new ListAccountAssignmentsQuery(
            $this->transport->uuid($input->getArgument('account')),
            $this->transport->limit($input->getOption('limit')),
            $this->transport->cursor($input->getOption('after')),
        );
        $result = $this->operator->run('assignments', fn (): mixed => $this->queries->ask($message));
        if (!$result instanceof ListAccountAssignmentsResult) {
            throw new \LogicException('Unexpected authorization result.');
        }
        $assignments = [];
        foreach ($result->assignments as $assignment) {
            $assignments[] = [
                'id' => $assignment->id->toRfc4122(),
                'source' => $assignment->source,
                'kind' => $assignment->kind,
                'key' => $assignment->key,
                'scope' => $assignment->scope,
                'resourceType' => $assignment->resourceType,
                'resourceId' => $assignment->resourceId?->toRfc4122(),
            ];
        }
        $next = null;
        if (null !== $result->next) {
            $json = json_encode([
                'accountId' => $result->next->accountId->toRfc4122(),
                'source' => $result->next->source,
                'assignmentId' => $result->next->assignmentId->toRfc4122(),
            ], JSON_THROW_ON_ERROR);
            $next = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        }

        return ['assignments' => $assignments, 'next' => $next];
    }

    /**
     * @param list<AssignmentChangeInput> $changes
     *
     * @return array<string, int>
     */
    private function dispatchChanges(Uuid $accountId, array $changes): array
    {
        $message = new ChangeAccountAssignmentsCommand($accountId, $changes);
        $result = $this->operator->run('assignments', fn (): mixed => $this->commands->dispatch($message));
        if (!$result instanceof ChangeAccountAssignmentsResult) {
            throw new \LogicException('Unexpected authorization result.');
        }

        return ['requested' => $result->requested, 'added' => $result->added, 'removed' => $result->removed, 'unchanged' => $result->unchanged];
    }

    /**
     * @param list<PermissionCheckInput> $checks
     *
     * @return non-empty-list<array{allowed: bool}>
     */
    private function evaluate(array $checks): array
    {
        $message = new EvaluatePermissionsQuery($checks);
        $result = $this->operator->run('assignments', fn (): mixed => $this->queries->ask($message));
        if (!$result instanceof EvaluatePermissionsResult || count($result->decisions) !== count($checks) || [] === $result->decisions) {
            throw new \LogicException('Unexpected authorization result.');
        }
        $decisions = [];
        foreach ($result->decisions as $decision) {
            $decisions[] = ['allowed' => $decision->allowed];
        }

        return $decisions;
    }
}
