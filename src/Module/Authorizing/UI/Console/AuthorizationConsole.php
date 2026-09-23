<?php

declare(strict_types=1);

namespace App\Module\Authorizing\UI\Console;

use App\Module\Authorizing\Application\ChangeSubjectAssignments\AssignmentChangeInput;
use App\Module\Authorizing\Application\ChangeSubjectAssignments\ChangeSubjectAssignmentsCommand;
use App\Module\Authorizing\Application\ChangeSubjectAssignments\ChangeSubjectAssignmentsResult;
use App\Module\Authorizing\Application\DefineRole\DefineRoleCommand;
use App\Module\Authorizing\Application\DefineRole\DefineRoleResult;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EntitlementCheckInput;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsQuery;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsResult;
use App\Module\Authorizing\Application\ListSubjectAssignments\ListSubjectAssignmentsQuery;
use App\Module\Authorizing\Application\ListSubjectAssignments\ListSubjectAssignmentsResult;
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
        return $this->dispatchChanges($this->transport->uuid($input->getArgument('subject')), [
            new AssignmentChangeInput($operation, $kind, $this->transport->text($input->getArgument($argument))),
        ]);
    }

    /** @return array<string, int> */
    public function changeBatch(InputInterface $input): array
    {
        $subjectId = $this->transport->uuid($input->getArgument('subject'));
        $changes = [];
        foreach ($this->transport->batch($input) as $item) {
            $fields = $this->transport->fields($item, ['operation', 'kind', 'key']);
            $changes[] = new AssignmentChangeInput(
                $this->transport->text($fields['operation']),
                $this->transport->text($fields['kind']),
                $this->transport->text($fields['key']),
            );
        }

        return $this->dispatchChanges($subjectId, $changes);
    }

    /** @return array{allowed: bool} */
    public function check(InputInterface $input): array
    {
        $decisions = $this->evaluate([
            new EntitlementCheckInput($this->transport->uuid($input->getArgument('subject')), $this->transport->text($input->getArgument('permission'))),
        ]);

        return $decisions[0];
    }

    /** @return array{decisions: list<array{allowed: bool}>} */
    public function checkBatch(InputInterface $input): array
    {
        $checks = [];
        foreach ($this->transport->batch($input) as $item) {
            $fields = $this->transport->fields($item, ['subjectId', 'permission']);
            $checks[] = new EntitlementCheckInput($this->transport->uuid($fields['subjectId']), $this->transport->text($fields['permission']));
        }

        return ['decisions' => $this->evaluate($checks)];
    }

    /** @return array<string, mixed> */
    public function assignments(InputInterface $input): array
    {
        $message = new ListSubjectAssignmentsQuery(
            $this->transport->uuid($input->getArgument('subject')),
            $this->transport->limit($input->getOption('limit')),
            $this->transport->cursor($input->getOption('after')),
        );
        $result = $this->operator->run('assignments', fn (): mixed => $this->queries->ask($message));
        if (!$result instanceof ListSubjectAssignmentsResult) {
            throw new \LogicException('Unexpected authorization result.');
        }
        $assignments = [];
        foreach ($result->assignments as $assignment) {
            $assignments[] = [
                'kind' => $assignment->kind,
                'key' => $assignment->key,
            ];
        }
        $next = null;
        if (null !== $result->next) {
            $json = json_encode([
                'v' => 1,
                'subjectId' => $result->next->subjectId->toRfc4122(),
                'kind' => $result->next->kind,
                'key' => $result->next->key,
            ], JSON_THROW_ON_ERROR);
            $next = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        }

        return ['assignments' => $assignments, 'next' => $next];
    }

    /** @return array<string, mixed> */
    public function defineRole(InputInterface $input): array
    {
        $permissions = $input->getArgument('permissions');
        if (!is_array($permissions) || !array_is_list($permissions)) {
            throw new \DomainException('Invalid authorization input.');
        }
        $permissionKeys = [];
        foreach ($permissions as $permission) {
            $permissionKeys[] = $this->transport->text($permission);
        }
        $label = $input->getArgument('label');
        if (!is_string($label) || strlen($label) > 400) {
            throw new \DomainException('Invalid authorization input.');
        }
        $message = new DefineRoleCommand(
            $this->transport->text($input->getArgument('key')),
            $label,
            $permissionKeys,
            $this->revision($input->getOption('expected-revision')),
            true === $input->getOption('if-absent'),
        );
        $result = $this->operator->run('catalogue', fn (): mixed => $this->commands->dispatch($message));
        if (!$result instanceof DefineRoleResult) {
            throw new \LogicException('Unexpected authorization result.');
        }

        return [
            'key' => $result->key,
            'label' => $result->label,
            'revision' => $result->revision,
            'retiredAt' => $result->retiredAt?->format(\DateTimeInterface::ATOM),
            'permissions' => $result->permissions,
        ];
    }

    /**
     * @param list<AssignmentChangeInput> $changes
     *
     * @return array<string, int>
     */
    private function dispatchChanges(Uuid $subjectId, array $changes): array
    {
        $message = new ChangeSubjectAssignmentsCommand($subjectId, $changes);
        $result = $this->operator->run('assignments', fn (): mixed => $this->commands->dispatch($message));
        if (!$result instanceof ChangeSubjectAssignmentsResult) {
            throw new \LogicException('Unexpected authorization result.');
        }

        return ['requested' => $result->requested, 'added' => $result->added, 'removed' => $result->removed, 'unchanged' => $result->unchanged];
    }

    /**
     * @param list<EntitlementCheckInput> $checks
     *
     * @return non-empty-list<array{allowed: bool}>
     */
    private function evaluate(array $checks): array
    {
        $message = new EvaluateSubjectEntitlementsQuery($checks);
        $result = $this->operator->run('assignments', fn (): mixed => $this->queries->ask($message));
        if (!$result instanceof EvaluateSubjectEntitlementsResult || count($result->decisions) !== count($checks) || [] === $result->decisions) {
            throw new \LogicException('Unexpected authorization result.');
        }
        $decisions = [];
        foreach ($result->decisions as $decision) {
            $decisions[] = ['allowed' => $decision->allowed];
        }

        return $decisions;
    }

    private function revision(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || 1 !== preg_match('/\A[1-9][0-9]{0,9}\z/', $value)) {
            throw new \DomainException('Invalid authorization input.');
        }

        return (int) $value;
    }
}
