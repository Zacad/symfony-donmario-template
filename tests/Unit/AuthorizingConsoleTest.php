<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authorizing\Application\ChangeSubjectAssignments\ChangeSubjectAssignmentsCommand;
use App\Module\Authorizing\Application\ChangeSubjectAssignments\ChangeSubjectAssignmentsResult;
use App\Module\Authorizing\Application\DefineRole\DefineRoleCommand;
use App\Module\Authorizing\Application\DefineRole\DefineRoleResult;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EntitlementDecisionResult;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsQuery;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsResult;
use App\Module\Authorizing\Application\ListSubjectAssignments\AssignmentCursorResult;
use App\Module\Authorizing\Application\ListSubjectAssignments\ListSubjectAssignmentsQuery;
use App\Module\Authorizing\Application\ListSubjectAssignments\ListSubjectAssignmentsResult;
use App\Module\Authorizing\Application\ListSubjectAssignments\SubjectAssignmentResult;
use App\Module\Authorizing\UI\Console\AssignRoleConsoleCommand;
use App\Module\Authorizing\UI\Console\AuthorizationConsole;
use App\Module\Authorizing\UI\Console\AuthorizationConsoleInput;
use App\Module\Authorizing\UI\Console\ChangeAssignmentsBatchConsoleCommand;
use App\Module\Authorizing\UI\Console\CheckPermissionConsoleCommand;
use App\Module\Authorizing\UI\Console\CheckPermissionsBatchConsoleCommand;
use App\Module\Authorizing\UI\Console\DefineRoleConsoleCommand;
use App\Module\Authorizing\UI\Console\GrantPermissionConsoleCommand;
use App\Module\Authorizing\UI\Console\ListAssignmentsConsoleCommand;
use App\Module\Authorizing\UI\Console\RemoveRoleConsoleCommand;
use App\Module\Authorizing\UI\Console\RevokePermissionConsoleCommand;
use App\Platform\Authorization\ExecutionContext;
use App\Platform\Authorization\OperatorExecution;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\InvocationContext;
use App\Platform\Messaging\QueryBus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class AuthorizingConsoleTest extends TestCase
{
    private const string SUBJECT = '0194c680-7e00-7000-8000-000000000001';
    private const string OTHER_SUBJECT = '0194c680-7e00-7000-8000-000000000002';
    private const string CHANGE = '{"operation":"add","kind":"role","key":"task_tracking.user"}';
    private const string CHECK = '{"subjectId":"'.self::SUBJECT.'","permission":"task_tracking.task.view"}';

    /** @var list<object> */
    private array $messages = [];

    #[DataProvider('globalAddCommands')]
    public function testAssignAndGrantDispatchOnlyNaturalKindKeyInput(string $name, string $kind): void
    {
        $tester = new CommandTester($this->command($name));
        $argument = 'role' === $kind ? 'role' : 'permission';
        $key = 'role' === $kind ? 'task_tracking.user' : 'task_tracking.task.view';

        self::assertSame(0, $tester->execute(['subject' => self::SUBJECT, $argument => $key]));
        $message = $this->messages[0];
        self::assertInstanceOf(ChangeSubjectAssignmentsCommand::class, $message);
        self::assertSame($key, $message->changes[0]->key);
        self::assertSame(['operation', 'kind', 'key'], array_keys(get_object_vars($message->changes[0])));
    }

    /** @return iterable<string, array{string, string}> */
    public static function globalAddCommands(): iterable
    {
        yield 'role' => ['role:assign', 'role'];
        yield 'permission' => ['permission:grant', 'permission'];
    }

    #[DataProvider('removalCommands')]
    public function testRemoveAndRevokeDispatchOnlyNaturalKindKeyInput(string $name, string $argument): void
    {
        $tester = new CommandTester($this->command($name));
        self::assertSame(0, $tester->execute([
            'subject' => self::SUBJECT,
            $argument => 'retired.key',
        ]));

        $message = $this->messages[0];
        self::assertInstanceOf(ChangeSubjectAssignmentsCommand::class, $message);
        self::assertSame(['remove', 'retired.key'], [$message->changes[0]->operation, $message->changes[0]->key]);
        self::assertSame(['operation', 'kind', 'key'], array_keys(get_object_vars($message->changes[0])));
    }

    /** @return iterable<string, array{string, string}> */
    public static function removalCommands(): iterable
    {
        yield 'role' => ['role:remove', 'role'];
        yield 'permission' => ['permission:revoke', 'permission'];
    }

    public function testCheckIsGlobalWithoutScopeOptions(): void
    {
        $tester = new CommandTester($this->command('check', static fn (): EvaluateSubjectEntitlementsResult => new EvaluateSubjectEntitlementsResult([
            new EntitlementDecisionResult(true),
        ])));

        self::assertSame(0, $tester->execute(['subject' => self::SUBJECT, 'permission' => 'task_tracking.task.view']));
        self::assertSame("{\"allowed\":true}\n", $tester->getDisplay());
        $query = $this->messages[0];
        self::assertInstanceOf(EvaluateSubjectEntitlementsQuery::class, $query);
        self::assertSame(['subjectId', 'permission'], array_keys(get_object_vars($query->checks[0])));
    }

    public function testBatchForwardsOnlyOperationKindAndKey(): void
    {
        $json = '['.self::CHANGE.',{"operation":"remove","kind":"permission","key":"retired.permission"}]';
        [$status] = $this->runStdin('change-batch', $json);

        self::assertSame(0, $status);
        $message = $this->messages[0];
        self::assertInstanceOf(ChangeSubjectAssignmentsCommand::class, $message);
        self::assertSame(['operation', 'kind', 'key'], array_keys(get_object_vars($message->changes[0])));
        self::assertSame(['remove', 'permission', 'retired.permission'], [$message->changes[1]->operation, $message->changes[1]->kind, $message->changes[1]->key]);
    }

    public function testCheckBatchUsesOnlySubjectAndPermissionAndPreservesOrder(): void
    {
        $json = '['.self::CHECK.',{"subjectId":"'.self::OTHER_SUBJECT.'","permission":"authorizing.manage"}]';
        [$status, $output] = $this->runStdin('check-batch', $json, static fn (): EvaluateSubjectEntitlementsResult => new EvaluateSubjectEntitlementsResult([
            new EntitlementDecisionResult(true),
            new EntitlementDecisionResult(false),
        ]));

        self::assertSame(0, $status);
        self::assertSame('{"decisions":[{"allowed":true},{"allowed":false}]}', trim($output));
    }

    public function testAssignmentListUsesAndReturnsSubjectBoundNaturalCursor(): void
    {
        $cursor = rtrim(strtr(base64_encode(json_encode([
            'v' => 1,
            'subjectId' => self::SUBJECT,
            'kind' => 'role',
            'key' => 'role.a',
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $tester = new CommandTester($this->command('assignments', static fn (): ListSubjectAssignmentsResult => new ListSubjectAssignmentsResult(
            [new SubjectAssignmentResult('permission', 'authorizing.manage')],
            new AssignmentCursorResult(\Symfony\Component\Uid\Uuid::fromString(self::SUBJECT), 'permission', 'authorizing.manage'),
        )));

        self::assertSame(0, $tester->execute(['subject' => self::SUBJECT, '--after' => $cursor]));
        $query = $this->messages[0];
        self::assertInstanceOf(ListSubjectAssignmentsQuery::class, $query);
        self::assertNotNull($query->after);
        self::assertSame(['subjectId', 'kind', 'key'], array_keys(get_object_vars($query->after)));
        self::assertSame(['role', 'role.a'], [$query->after->kind, $query->after->key]);

        $output = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($output);
        self::assertSame([['kind' => 'permission', 'key' => 'authorizing.manage']], $output['assignments']);
        self::assertIsString($output['next']);
    }

    public function testDefineRoleOutputUsesCleanRoleShape(): void
    {
        $tester = new CommandTester($this->command('role:define'));

        self::assertSame(0, $tester->execute([
            'key' => 'operations.lead',
            'label' => 'Operations lead',
            'permissions' => ['authorizing.manage', 'task_tracking.task.view'],
        ]));
        $output = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($output);
        self::assertSame(['key', 'label', 'revision', 'retiredAt', 'permissions'], array_keys($output));
    }

    /** @param (callable(object): mixed)|null $handler */
    private function command(string $name, ?callable $handler = null): Command
    {
        $execution = new ExecutionContext(new InvocationContext(), new RequestStack(), new TokenStorage(), new AuthenticationTrustResolver());
        $handle = function (object $message) use ($handler): mixed {
            $this->messages[] = $message;
            if (null !== $handler) {
                return $handler($message);
            }

            return match (true) {
                $message instanceof ChangeSubjectAssignmentsCommand => new ChangeSubjectAssignmentsResult(count($message->changes), count($message->changes), 0, 0),
                $message instanceof DefineRoleCommand => new DefineRoleResult($message->key, $message->label, 1, null, $message->permissions),
                $message instanceof EvaluateSubjectEntitlementsQuery => new EvaluateSubjectEntitlementsResult(array_fill(0, count($message->checks), new EntitlementDecisionResult(false))),
                $message instanceof ListSubjectAssignmentsQuery => new ListSubjectAssignmentsResult([]),
                default => throw new \LogicException('Unexpected test message.'),
            };
        };
        $commands = new CommandBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ChangeSubjectAssignmentsCommand::class => [$handle],
            DefineRoleCommand::class => [$handle],
        ]))]));
        $queries = new QueryBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            EvaluateSubjectEntitlementsQuery::class => [$handle],
            ListSubjectAssignmentsQuery::class => [$handle],
        ]))]));
        $transport = new AuthorizationConsoleInput();
        $console = new AuthorizationConsole($commands, $queries, $transport, new OperatorExecution($execution));

        return match ($name) {
            'role:assign' => new AssignRoleConsoleCommand($console),
            'role:remove' => new RemoveRoleConsoleCommand($console),
            'permission:grant' => new GrantPermissionConsoleCommand($console),
            'permission:revoke' => new RevokePermissionConsoleCommand($console),
            'check' => new CheckPermissionConsoleCommand($console),
            'change-batch' => new ChangeAssignmentsBatchConsoleCommand($console),
            'check-batch' => new CheckPermissionsBatchConsoleCommand($console),
            'assignments' => new ListAssignmentsConsoleCommand($console),
            'role:define' => new DefineRoleConsoleCommand($console),
            default => throw new \LogicException('Unknown test command.'),
        };
    }

    /**
     * @param (callable(object): mixed)|null $handler
     *
     * @return array{int, string}
     */
    private function runStdin(string $name, string $json, ?callable $handler = null): array
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $json);
        rewind($stream);
        $input = new ArrayInput('change-batch' === $name ? ['subject' => self::SUBJECT] : []);
        $input->setStream($stream);
        $output = new BufferedOutput();
        try {
            return [$this->command($name, $handler)->run($input, $output), $output->fetch()];
        } finally {
            fclose($stream);
        }
    }
}
