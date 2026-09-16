<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authorizing\Application\ChangeAccountAssignments\ChangeAccountAssignmentsCommand;
use App\Module\Authorizing\Application\ChangeAccountAssignments\ChangeAccountAssignmentsResult;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsQuery;
use App\Module\Authorizing\Application\EvaluatePermissions\EvaluatePermissionsResult;
use App\Module\Authorizing\Application\EvaluatePermissions\PermissionDecisionResult;
use App\Module\Authorizing\Application\ListAccountAssignments\AccountAssignmentResult;
use App\Module\Authorizing\Application\ListAccountAssignments\AssignmentCursorResult;
use App\Module\Authorizing\Application\ListAccountAssignments\ListAccountAssignmentsQuery;
use App\Module\Authorizing\Application\ListAccountAssignments\ListAccountAssignmentsResult;
use App\Module\Authorizing\UI\Console\AssignRoleConsoleCommand;
use App\Module\Authorizing\UI\Console\AuthorizationConsole;
use App\Module\Authorizing\UI\Console\AuthorizationConsoleInput;
use App\Module\Authorizing\UI\Console\ChangeAssignmentsBatchConsoleCommand;
use App\Module\Authorizing\UI\Console\CheckPermissionConsoleCommand;
use App\Module\Authorizing\UI\Console\CheckPermissionsBatchConsoleCommand;
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
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

final class AuthorizingConsoleTest extends TestCase
{
    private const string ACCOUNT = '0194c680-7e00-7000-8000-000000000001';
    private const string RESOURCE = '0194c680-7e00-7000-8000-000000000002';
    private const string ASSIGNMENT = '0194c680-7e00-7000-8000-000000000003';
    private const string CHANGE = '{"operation":"add","kind":"role","key":"task_tracking.reader","scope":"global"}';
    private const string CHECK = '{"accountId":"'.self::ACCOUNT.'","permission":"task_tracking.task.view","scope":"global"}';

    /** @var list<object> */
    private array $messages = [];

    #[DataProvider('singleChanges')]
    public function testFriendlyCommandsDispatchOneCorrectChange(string $name, string $operation, string $kind, bool $resource): void
    {
        $command = $this->command($name);
        $options = $resource ? ['--resource-type=task_tracking.task', '--resource-id='.self::RESOURCE] : ['--global'];
        $input = new ArgvInput(['console', self::ACCOUNT, ' Retired.Key ', ...$options]);
        $output = new BufferedOutput();

        self::assertSame(0, $command->run($input, $output));
        self::assertSame('{"requested":1,"added":1,"removed":0,"unchanged":0}', trim($output->fetch()));
        self::assertCount(1, $this->messages);
        $message = $this->messages[0];
        self::assertInstanceOf(ChangeAccountAssignmentsCommand::class, $message);
        self::assertSame(self::ACCOUNT, $message->accountId->toRfc4122());
        self::assertCount(1, $message->changes);
        $change = $message->changes[0];
        self::assertSame($operation, $change->operation);
        self::assertSame($kind, $change->kind);
        self::assertSame(' Retired.Key ', $change->key, 'Canonical names and retired-key semantics belong to the business handler.');
        self::assertSame($resource ? 'resource' : 'global', $change->scope);
        self::assertSame($resource ? 'task_tracking.task' : null, $change->resourceType);
        self::assertSame($resource ? self::RESOURCE : null, $change->resourceId?->toRfc4122());
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function singleChanges(): iterable
    {
        foreach (['role:assign' => ['add', 'role'], 'role:remove' => ['remove', 'role'], 'permission:grant' => ['add', 'permission'], 'permission:revoke' => ['remove', 'permission']] as $name => [$operation, $kind]) {
            yield $name.' global' => [$name, $operation, $kind, false];
            yield $name.' resource' => [$name, $operation, $kind, true];
        }
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidSingleInputs')]
    public function testMalformedSingleInputNeverReachesBus(array $options): void
    {
        foreach (['role:assign', 'role:remove', 'permission:grant', 'permission:revoke', 'check'] as $name) {
            $argument = str_starts_with($name, 'role:') ? 'role' : 'permission';
            $tester = new CommandTester($this->command($name));
            self::assertSame(2, $tester->execute(array_replace(['account' => self::ACCOUNT, $argument => 'task_tracking.reader'], $options)));
            self::assertSame("Invalid authorization input.\n", $tester->getDisplay());
        }
        self::assertSame([], $this->messages);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSingleInputs(): iterable
    {
        yield 'no implicit global' => [[]];
        yield 'type alone' => [['--resource-type' => 'task_tracking.task']];
        yield 'id alone' => [['--resource-id' => self::RESOURCE]];
        yield 'global plus type' => [['--global' => true, '--resource-type' => 'task_tracking.task']];
        yield 'global plus id' => [['--global' => true, '--resource-id' => self::RESOURCE]];
        yield 'global plus pair' => [['--global' => true, '--resource-type' => 'task_tracking.task', '--resource-id' => self::RESOURCE]];
        yield 'malformed account' => [['--global' => true, 'account' => 'private-canary']];
        yield 'malformed resource' => [['--resource-type' => 'task_tracking.task', '--resource-id' => 'private-canary']];
        yield 'oversized type' => [['--resource-type' => str_repeat('x', 65), '--resource-id' => self::RESOURCE]];
    }

    public function testOversizedArgumentIsRejectedWithoutEchoingIt(): void
    {
        $tester = new CommandTester($this->command('check'));
        self::assertSame(2, $tester->execute(['account' => self::ACCOUNT, 'permission' => str_repeat('x', 65), '--global' => true]));
        self::assertSame("Invalid authorization input.\n", $tester->getDisplay());
        self::assertSame([], $this->messages);
    }

    public function testCheckDenialIsSuccessfulJsonAndUsesOneItemQuery(): void
    {
        $tester = new CommandTester($this->command('check'));
        self::assertSame(0, $tester->execute(['account' => self::ACCOUNT, 'permission' => 'task_tracking.task.view', '--resource-type' => 'task_tracking.task', '--resource-id' => self::RESOURCE]));
        self::assertSame("{\"allowed\":false}\n", $tester->getDisplay());
        self::assertCount(1, $this->messages);
        $query = $this->messages[0];
        self::assertInstanceOf(EvaluatePermissionsQuery::class, $query);
        self::assertCount(1, $query->checks);
        self::assertSame(self::ACCOUNT, $query->checks[0]->accountId->toRfc4122());
        self::assertSame('task_tracking.task.view', $query->checks[0]->permission);
        self::assertSame('resource', $query->checks[0]->scope);
        self::assertSame('task_tracking.task', $query->checks[0]->resourceType);
        self::assertSame(self::RESOURCE, $query->checks[0]->resourceId?->toRfc4122());
    }

    public function testChangeBatchUsesProvidedStreamAndPreservesOrderAndRawBusinessNames(): void
    {
        $json = '['.self::CHANGE.',{"operation":"remove","kind":"permission","key":" Retired.Key ","scope":"resource","resourceType":"task_tracking.task","resourceId":"'.self::RESOURCE.'"}]';
        [$status, $output] = $this->runStdin('change-batch', $json);

        self::assertSame(0, $status);
        self::assertSame('{"requested":2,"added":2,"removed":0,"unchanged":0}', trim($output));
        self::assertCount(1, $this->messages);
        $message = $this->messages[0];
        self::assertInstanceOf(ChangeAccountAssignmentsCommand::class, $message);
        self::assertSame(self::ACCOUNT, $message->accountId->toRfc4122());
        self::assertCount(2, $message->changes);
        self::assertSame('add', $message->changes[0]->operation);
        self::assertSame('role', $message->changes[0]->kind);
        self::assertSame('global', $message->changes[0]->scope);
        self::assertNull($message->changes[0]->resourceType);
        self::assertNull($message->changes[0]->resourceId);
        self::assertSame('remove', $message->changes[1]->operation);
        self::assertSame('permission', $message->changes[1]->kind);
        self::assertSame(' Retired.Key ', $message->changes[1]->key);
        self::assertSame('resource', $message->changes[1]->scope);
        self::assertSame('task_tracking.task', $message->changes[1]->resourceType);
        self::assertSame(self::RESOURCE, $message->changes[1]->resourceId?->toRfc4122());
    }

    public function testNativeCommandTesterInputStreamWorksAndMutationCountsArePreserved(): void
    {
        $tester = new CommandTester($this->command('change-batch', static fn (): ChangeAccountAssignmentsResult => new ChangeAccountAssignmentsResult(3, 1, 1, 1)));
        $tester->setInputs(['['.implode(',', array_fill(0, 3, self::CHANGE)).']']);

        self::assertSame(0, $tester->execute(['account' => self::ACCOUNT]));
        self::assertSame("{\"requested\":3,\"added\":1,\"removed\":1,\"unchanged\":1}\n", $tester->getDisplay());
        self::assertCount(1, $this->messages);
    }

    public function testGlobalAllowAndTransportLengthBoundaryReachTheQueryUnchanged(): void
    {
        $tester = new CommandTester($this->command('check', static fn (): EvaluatePermissionsResult => new EvaluatePermissionsResult([new PermissionDecisionResult(true)])));
        $permission = str_repeat('x', 64);
        self::assertSame(0, $tester->execute(['account' => self::ACCOUNT, 'permission' => $permission, '--global' => true]));
        self::assertSame("{\"allowed\":true}\n", $tester->getDisplay());
        $query = $this->messages[0];
        self::assertInstanceOf(EvaluatePermissionsQuery::class, $query);
        self::assertSame($permission, $query->checks[0]->permission);
        self::assertSame('global', $query->checks[0]->scope);
        self::assertNull($query->checks[0]->resourceType);
        self::assertNull($query->checks[0]->resourceId);
    }

    public function testCheckBatchReturnsOrderedDecisionsForMultipleAccounts(): void
    {
        $json = '['.self::CHECK.',{"accountId":"'.self::RESOURCE.'","permission":"task_tracking.task.complete","scope":"resource","resourceType":"task_tracking.task","resourceId":"'.self::ASSIGNMENT.'"}]';
        [$status, $output] = $this->runStdin('check-batch', $json, static fn (): EvaluatePermissionsResult => new EvaluatePermissionsResult([new PermissionDecisionResult(true), new PermissionDecisionResult(false)]));

        self::assertSame(0, $status);
        self::assertSame('{"decisions":[{"allowed":true},{"allowed":false}]}', trim($output));
        self::assertCount(1, $this->messages);
        $query = $this->messages[0];
        self::assertInstanceOf(EvaluatePermissionsQuery::class, $query);
        self::assertCount(2, $query->checks);
        self::assertSame(self::ACCOUNT, $query->checks[0]->accountId->toRfc4122());
        self::assertSame('global', $query->checks[0]->scope);
        self::assertSame(self::RESOURCE, $query->checks[1]->accountId->toRfc4122());
        self::assertSame('task_tracking.task.complete', $query->checks[1]->permission);
        self::assertSame('task_tracking.task', $query->checks[1]->resourceType);
        self::assertSame(self::ASSIGNMENT, $query->checks[1]->resourceId?->toRfc4122());
    }

    #[DataProvider('invalidBatches')]
    public function testInvalidBatchTransportNeverDispatches(string $name, string $json): void
    {
        [$status, $output] = $this->runStdin($name, $json);
        self::assertSame(2, $status);
        self::assertSame("Invalid authorization input.\n", $output);
        self::assertSame([], $this->messages);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidBatches(): iterable
    {
        foreach (['change-batch' => self::CHANGE, 'check-batch' => self::CHECK] as $name => $item) {
            foreach ([
                'empty' => '',
                'empty list' => '[]',
                'object' => '{}',
                'numeric-key object' => '{"0":'.$item.'}',
                'scalar' => '1',
                'null' => 'null',
                'array item' => '[[]]',
                'null item' => '[null]',
                'scalar item' => '[1]',
                'trailing data' => '['.$item.'] canary',
                'too many items' => '['.implode(',', array_fill(0, 101, $item)).']',
                'oversized bytes' => '['.$item.']'.str_repeat(' ', 65536),
                'too deep' => str_repeat('[', 17).'0'.str_repeat(']', 17),
                'invalid UTF8' => "[\"\xff\"]",
                'unknown field' => '['.substr($item, 0, -1).',"actor":"canary"}]',
                'missing scope' => '['.str_replace(',"scope":"global"', '', $item).']',
                'numeric scope' => '['.str_replace('"scope":"global"', '"scope":1', $item).']',
                'null scope' => '['.str_replace('"scope":"global"', '"scope":null', $item).']',
                'unknown scope' => '['.str_replace('"scope":"global"', '"scope":"implicit"', $item).']',
                'resource missing pair' => '['.str_replace('"scope":"global"', '"scope":"resource"', $item).']',
                'global with resource' => '['.substr($item, 0, -1).',"resourceType":"task_tracking.task","resourceId":"'.self::RESOURCE.'"}]',
                'optional null' => '['.substr($item, 0, -1).',"resourceType":null}]',
                'resource type array' => '['.substr($item, 0, -1).',"resourceType":[]}]',
                'resource type object' => '['.substr($item, 0, -1).',"resourceType":{}}]',
                'resource ID malformed' => '['.substr($item, 0, -1).',"resourceId":"canary"}]',
                'resource ID numeric' => '['.substr($item, 0, -1).',"resourceId":1}]',
                'resource ID null' => '['.substr($item, 0, -1).',"resourceId":null}]',
            ] as $case => $json) {
                yield $name.' '.$case => [$name, $json];
            }
        }
        foreach (['operation', 'kind', 'key'] as $field) {
            foreach ([1, true, null, [], new \stdClass(), str_repeat('x', 65)] as $index => $value) {
                $item = ['operation' => 'add', 'kind' => 'role', 'key' => 'task_tracking.reader', 'scope' => 'global'];
                $item[$field] = $value;
                yield 'change '.$field.' '.$index => ['change-batch', json_encode([$item], JSON_THROW_ON_ERROR)];
            }
        }
        foreach (['accountId', 'permission'] as $field) {
            foreach ([1, true, null, [], new \stdClass(), str_repeat('x', 65)] as $index => $value) {
                $item = ['accountId' => self::ACCOUNT, 'permission' => 'task_tracking.task.view', 'scope' => 'global'];
                $item[$field] = $value;
                yield 'check '.$field.' '.$index => ['check-batch', json_encode([$item], JSON_THROW_ON_ERROR)];
            }
        }
        yield 'malformed batch account UUID' => ['check-batch', '['.str_replace(self::ACCOUNT, 'canary', self::CHECK).']'];
        yield 'later invalid change prevents entire dispatch' => ['change-batch', '['.self::CHANGE.',{}]'];
    }

    public function testBatchByteLimitReadsOnlyOneOverflowByte(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, '['.self::CHECK.']'.str_repeat(' ', 100000));
        rewind($stream);
        $input = new ArrayInput([]);
        $input->setStream($stream);
        $output = new BufferedOutput();
        try {
            self::assertSame(2, $this->command('check-batch')->run($input, $output));
            self::assertSame(65537, ftell($stream));
            self::assertSame("Invalid authorization input.\n", $output->fetch());
            self::assertSame([], $this->messages);
        } finally {
            fclose($stream);
        }
    }

    public function testExactlyOneHundredItemsAndSixtyFourKiBReachBus(): void
    {
        foreach (['change-batch' => self::CHANGE, 'check-batch' => self::CHECK] as $name => $item) {
            $json = '['.implode(',', array_fill(0, 100, $item)).']';
            $json = str_pad($json, 65536, ' ');
            [$status] = $this->runStdin($name, $json);
            self::assertSame(0, $status, 'Transport accepts the bound; business duplicate rules remain in the handler.');
        }
        self::assertCount(2, $this->messages);
        $change = $this->messages[0];
        $check = $this->messages[1];
        self::assertInstanceOf(ChangeAccountAssignmentsCommand::class, $change);
        self::assertInstanceOf(EvaluatePermissionsQuery::class, $check);
        self::assertCount(100, $change->changes);
        self::assertCount(100, $check->checks);
    }

    #[DataProvider('operationFailures')]
    public function testHandlerFailuresAreFixedAndDoNotMasqueradeAsDenial(string $name, string $failure, int $status): void
    {
        $handler = static function (object $message) use ($failure): never {
            throw match ($failure) {
                'domain' => new \DomainException('private-canary'),
                'validation' => new ValidationFailedException($message, new ConstraintViolationList([
                    new ConstraintViolation('private-canary', null, [], null, 'private-canary', 'private-canary'),
                ])),
                default => new \RuntimeException('private-canary'),
            };
        };
        $tester = new CommandTester($this->command($name, $handler));
        $arguments = ['account' => self::ACCOUNT];
        if ('assignments' !== $name) {
            $arguments[str_starts_with($name, 'role:') ? 'role' : 'permission'] = 'task_tracking.reader';
            $arguments['--global'] = true;
        }
        self::assertSame($status, $tester->execute($arguments, ['verbosity' => BufferedOutput::VERBOSITY_DEBUG]));
        self::assertSame(2 === $status ? "Invalid authorization input.\n" : "Authorization operation failed.\n", $tester->getDisplay());
        self::assertCount(1, $this->messages);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function operationFailures(): iterable
    {
        foreach (['role:assign', 'role:remove', 'permission:grant', 'permission:revoke', 'check', 'assignments'] as $name) {
            foreach (['domain' => 2, 'validation' => 2, 'unexpected' => 1] as $failure => $status) {
                yield $name.' '.$failure => [$name, $failure, $status];
            }
        }
    }

    public function testUnexpectedOutputAndWrongDecisionCardinalityAreOperationErrors(): void
    {
        foreach ([null, new EvaluatePermissionsResult([]), new EvaluatePermissionsResult([new PermissionDecisionResult(true), new PermissionDecisionResult(false)])] as $result) {
            $tester = new CommandTester($this->command('check', static fn (): mixed => $result));
            self::assertSame(1, $tester->execute(['account' => self::ACCOUNT, 'permission' => 'task_tracking.task.view', '--global' => true]));
            self::assertSame("Authorization operation failed.\n", $tester->getDisplay());
        }
    }

    public function testBatchFailuresNeverReturnPartialOutput(): void
    {
        foreach (['change-batch' => self::CHANGE, 'check-batch' => self::CHECK] as $name => $item) {
            foreach ([new \DomainException('private-canary'), new \RuntimeException('private-canary')] as $failure) {
                [$status, $output] = $this->runStdin($name, '['.$item.']', static function () use ($failure): never {
                    throw $failure;
                });
                $invalid = $failure instanceof \DomainException;
                self::assertSame($invalid ? 2 : 1, $status);
                self::assertSame($invalid ? "Invalid authorization input.\n" : "Authorization operation failed.\n", $output);
            }
        }
    }

    public function testListingMapsUuidsAndRoundTripsOpaqueCursor(): void
    {
        $account = Uuid::fromString(self::ACCOUNT);
        $assignment = Uuid::fromString(self::ASSIGNMENT);
        $page = new ListAccountAssignmentsResult([
            new AccountAssignmentResult($assignment, 3, 'permission', 'task_tracking.task.view', 'resource', 'task_tracking.task', Uuid::fromString(self::RESOURCE)),
        ], new AssignmentCursorResult($account, 3, $assignment));
        $tester = new CommandTester($this->command('assignments', static fn (): ListAccountAssignmentsResult => $page));
        self::assertSame(0, $tester->execute(['account' => self::ACCOUNT]));
        $expectedCursor = self::cursor(['accountId' => self::ACCOUNT, 'source' => 3, 'assignmentId' => self::ASSIGNMENT]);
        self::assertSame([
            'assignments' => [[
                'id' => self::ASSIGNMENT, 'source' => 3, 'kind' => 'permission', 'key' => 'task_tracking.task.view',
                'scope' => 'resource', 'resourceType' => 'task_tracking.task', 'resourceId' => self::RESOURCE,
            ]],
            'next' => $expectedCursor,
        ], json_decode($tester->getDisplay(), true, 16, JSON_THROW_ON_ERROR));
        $tester = new CommandTester($this->command('assignments'));
        self::assertSame(0, $tester->execute(['account' => self::ACCOUNT, '--limit' => '100', '--after' => $expectedCursor]));
        self::assertSame("{\"assignments\":[],\"next\":null}\n", $tester->getDisplay());
        self::assertCount(2, $this->messages);
        $first = $this->messages[0];
        $second = $this->messages[1];
        self::assertInstanceOf(ListAccountAssignmentsQuery::class, $first);
        self::assertInstanceOf(ListAccountAssignmentsQuery::class, $second);
        self::assertSame(50, $first->limit);
        self::assertNull($first->after);
        self::assertSame(100, $second->limit);
        self::assertNotNull($second->after);
        self::assertSame(self::ACCOUNT, $second->after->accountId->toRfc4122());
        self::assertSame(3, $second->after->source);
        self::assertSame(self::ASSIGNMENT, $second->after->assignmentId->toRfc4122());
    }

    #[DataProvider('invalidPagination')]
    public function testMalformedPaginationNeverDispatches(string $option, string $value): void
    {
        $tester = new CommandTester($this->command('assignments'));
        self::assertSame(2, $tester->execute(['account' => self::ACCOUNT, $option => $value]));
        self::assertSame("Invalid authorization input.\n", $tester->getDisplay());
        self::assertSame([], $this->messages);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPagination(): iterable
    {
        foreach (['0', '101', '-1', '1.0', '1e2', '+1', ' 1', '01', '', str_repeat('9', 100)] as $value) {
            yield 'limit '.$value => ['--limit', $value];
        }
        foreach (['', '!', 'e30=', 'e31', str_repeat('A', 513), 'W10', 'bnVsbA', 'ew'] as $index => $cursor) {
            yield 'cursor encoding '.$index => ['--after', $cursor];
        }
        $valid = ['accountId' => self::ACCOUNT, 'source' => 0, 'assignmentId' => self::ASSIGNMENT];
        foreach ([-1, 4, '0', 0.5, null, true, []] as $index => $source) {
            yield 'cursor source '.$index => ['--after', self::cursor(array_replace($valid, ['source' => $source]))];
        }
        yield 'cursor unknown field' => ['--after', self::cursor([...$valid, 'actor' => 'canary'])];
        yield 'cursor missing field' => ['--after', self::cursor(['source' => 0, 'assignmentId' => self::ASSIGNMENT])];
        yield 'cursor invalid account' => ['--after', self::cursor(array_replace($valid, ['accountId' => 'canary']))];
        yield 'cursor nonstring assignment' => ['--after', self::cursor(array_replace($valid, ['assignmentId' => 1]))];
    }

    public function testCursorAccountMismatchIsPassedToBusinessHandler(): void
    {
        $tester = new CommandTester($this->command('assignments'));
        self::assertSame(0, $tester->execute([
            'account' => self::ACCOUNT,
            '--after' => self::cursor(['accountId' => self::RESOURCE, 'source' => 0, 'assignmentId' => self::ASSIGNMENT]),
        ]));
        $query = $this->messages[0];
        self::assertInstanceOf(ListAccountAssignmentsQuery::class, $query);
        self::assertSame(self::ACCOUNT, $query->accountId->toRfc4122());
        self::assertNotNull($query->after);
        self::assertSame(self::RESOURCE, $query->after->accountId->toRfc4122());
    }

    /** @param array<string, mixed> $fields */
    private static function cursor(array $fields): string
    {
        return rtrim(strtr(base64_encode(json_encode($fields, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
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
        $input = new ArrayInput('change-batch' === $name ? ['account' => self::ACCOUNT] : []);
        $input->setStream($stream);
        $output = new BufferedOutput();
        try {
            return [$this->command($name, $handler)->run($input, $output), $output->fetch()];
        } finally {
            fclose($stream);
        }
    }

    /** @param (callable(object): mixed)|null $handler */
    private function command(string $name, ?callable $handler = null): Command
    {
        $execution = new ExecutionContext(new InvocationContext(), new RequestStack(), new TokenStorage(), new AuthenticationTrustResolver());
        $handle = function (object $message) use ($handler, $execution): mixed {
            $context = $execution->enter($message::class);
            try {
                self::assertTrue($context->actor->isOperator('assignments'));
            } finally {
                $execution->leave();
            }
            $this->messages[] = $message;
            if (null !== $handler) {
                return $handler($message);
            }

            return match (true) {
                $message instanceof ChangeAccountAssignmentsCommand => new ChangeAccountAssignmentsResult(count($message->changes), count($message->changes), 0, 0),
                $message instanceof EvaluatePermissionsQuery => new EvaluatePermissionsResult(array_fill(0, count($message->checks), new PermissionDecisionResult(false))),
                $message instanceof ListAccountAssignmentsQuery => new ListAccountAssignmentsResult([]),
                default => throw new \LogicException('Unexpected test message.'),
            };
        };
        $commands = new CommandBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ChangeAccountAssignmentsCommand::class => [$handle],
        ]))]));
        $queries = new QueryBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            EvaluatePermissionsQuery::class => [$handle],
            ListAccountAssignmentsQuery::class => [$handle],
        ]))]));
        $transport = new AuthorizationConsoleInput();
        $console = new AuthorizationConsole($commands, $queries, $transport, new OperatorExecution($execution));
        $command = match ($name) {
            'role:assign' => new AssignRoleConsoleCommand($console, $transport),
            'role:remove' => new RemoveRoleConsoleCommand($console, $transport),
            'permission:grant' => new GrantPermissionConsoleCommand($console, $transport),
            'permission:revoke' => new RevokePermissionConsoleCommand($console, $transport),
            'check' => new CheckPermissionConsoleCommand($console, $transport),
            'change-batch' => new ChangeAssignmentsBatchConsoleCommand($console),
            'check-batch' => new CheckPermissionsBatchConsoleCommand($console),
            'assignments' => new ListAssignmentsConsoleCommand($console),
            default => throw new \LogicException('Unknown test command.'),
        };
        self::assertSame('app:authorization:'.$name, $command->getName());

        return $command;
    }
}
