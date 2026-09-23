<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\TaskTracking\Application\CompleteTask\CompleteTaskCommand;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\GetTask\GetTaskQuery;
use App\Module\TaskTracking\Application\ListTasks\ListTasksQuery;
use App\Module\TaskTracking\Domain\InvalidTaskInput;
use App\Platform\Authorization\AuthorizationDenied;
use App\Tests\Fixtures\Authenticating\Browser;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Uid\Uuid;

final class TaskTrackingTest extends TaskTrackingTestCase
{
    public function testNativeBoundsAndAnonymousAdmissionPrecedeAnyBusinessSql(): void
    {
        $account = $this->account();
        $this->asAccount($account, function () use ($account): void {
            $this->sql->start();
            foreach ([new ListTasksQuery($account, 0), new ListTasksQuery($account, 101), new ListTasksQuery($account, after: str_repeat('x', 513))] as $query) {
                $this->fails(fn () => $this->queries->ask($query), ValidationFailedException::class);
            }
            $this->fails(fn () => $this->commands->dispatch(new CompleteTaskCommand('invalid')), ValidationFailedException::class);
            self::assertSame(0, $this->sql->events);
            $this->sql->stop();
        });
        $this->asAccount(null, function () use ($account): void {
            $this->sql->start();
            $this->fails(fn () => $this->queries->ask(new ListTasksQuery($account)));
            $this->fails(fn () => $this->commands->dispatch(new CompleteTaskCommand(Uuid::v7()->toRfc4122())));
            self::assertSame([], $this->sql->statements);
            $this->sql->stop();
        });
    }

    public function testAccountPermissionsRemainCoarseWhileVoterEnforcesOwnership(): void
    {
        $owner = $this->account();
        $other = $this->account();
        foreach ([self::CREATE, self::VIEW, self::COMPLETE] as $permission) {
            $this->grant($owner, $permission);
            $this->grant($other, $permission);
        }
        $password = Browser::secret();
        $this->observer->executeStatement('UPDATE authenticating_account SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), $owner->toRfc4122()]);
        $browser = Browser::create();
        Browser::login($browser, $this->email($owner), $password);
        $browser->jsonRequest('POST', '/_demo/tasks', ['title' => $this->prefix.'-http']);
        self::assertSame(201, $browser->getResponse()->getStatusCode());
        $payload = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);
        $task = Uuid::fromString($payload['id']);
        self::assertSame($owner->toRfc4122(), $this->observer->fetchOne('SELECT owner_account_id FROM task_tracking_task WHERE id = ?', [$task->toRfc4122()]));
        self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM authorizing_role_assignment WHERE subject_id = ?', [$owner->toRfc4122()]));
        self::assertSame(3, $this->observer->fetchOne('SELECT count(*) FROM authorizing_permission_grant WHERE subject_id = ?', [$owner->toRfc4122()]), 'Task creation must not create an authorization assignment.');
        $browser->request('GET', '/_demo/tasks/'.$task);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame([$task->toRfc4122()], $this->ids($this->page($owner)));
        self::assertTrue($this->complete($owner, $task)?->changed);

        $this->asAccount($other, function () use ($owner, $task): void {
            foreach ([new GetTaskQuery($task->toRfc4122()), new ListTasksQuery($owner)] as $query) {
                $this->fails(fn () => $this->queries->ask($query));
            }
            $this->fails(fn () => $this->commands->dispatch(new CompleteTaskCommand($task->toRfc4122())));
            foreach ([new CreateTaskCommand($this->prefix.'-unowned'), new CreateTaskCommand($this->prefix.'-foreign', $owner)] as $command) {
                $this->fails(fn () => $this->commands->dispatch($command));
            }
        });
        self::assertSame([], $this->page($other)->tasks);
    }

    public function testAdminPermissionDoesNotBypassOwnerAndUnownedTasksRemainAccountInaccessible(): void
    {
        $account = $this->account();
        foreach ([self::VIEW, self::COMPLETE, 'authorizing.manage', 'authorizing.catalogue.manage'] as $permission) {
            $this->grant($account, $permission);
        }
        $foreign = $this->task(ownerAccountId: Uuid::v7());
        $unowned = $this->task();

        $this->asAccount($account, function () use ($foreign, $unowned): void {
            foreach ([$foreign, $unowned] as $task) {
                $this->fails(fn () => $this->queries->ask(new GetTaskQuery($task->toRfc4122())));
                $this->fails(fn () => $this->commands->dispatch(new CompleteTaskCommand($task->toRfc4122())));
            }
        });
        self::assertSame([], $this->page($account)->tasks);
        self::assertSame($foreign->toRfc4122(), $this->json($this->cli(['app:task:show', $foreign->toRfc4122()]))['id']);
        self::assertSame($unowned->toRfc4122(), $this->json($this->cli(['app:task:show', $unowned->toRfc4122()]))['id']);
    }

    public function testOperatorCliMayFilterAnyOwnerIncludingDeletedOwners(): void
    {
        $owner = $this->account();
        $owned = trim($this->cli(['app:task:create', $this->prefix.'-owned', '--owner='.$owner])->getOutput());
        $unowned = trim($this->cli(['app:task:create', $this->prefix.'-unowned'])->getOutput());
        self::assertTrue(Uuid::isValid($owned));
        self::assertTrue(Uuid::isValid($unowned));
        self::assertSame([$owned], $this->cliTaskIds(['app:task:list', '--owner='.$owner, '--limit=100']));
        $this->observer->executeStatement('DELETE FROM authenticating_account WHERE id = ?', [$owner->toRfc4122()]);
        self::assertSame([$owned], $this->cliTaskIds(['app:task:list', '--owner='.$owner, '--limit=100']));
        $all = $this->cliTaskIds(['app:task:list', '--limit=100']);
        self::assertContains($owned, $all);
        self::assertContains($unowned, $all);

        foreach ([['app:task:create', 'valid', '--owner=bad'], ['app:task:complete', 'bad'], ['app:task:list', '--owner=bad'], ['app:task:list', '--limit=0'], ['app:task:list', '--limit=101'], ['app:task:list', '--limit=01'], ['app:task:list', '--after=e30'], ['app:task:list', '--after='.str_repeat('a', 513)]] as $arguments) {
            $this->fixedFailure($this->cli($arguments), 2);
        }
        $this->fixedFailure($this->cli(['app:task:complete', Uuid::v7()->toRfc4122()]), 1, 'Task not found.');
        $this->fixedFailure($this->cli(['app:task:create', $this->prefix.'-missing-owner', '--owner='.Uuid::v7()]), 1, 'Task operation failed.');
    }

    public function testOwnerKeysetCursorIsBoundAndAccountDeletionRevokesAdmission(): void
    {
        $owner = $this->account();
        $other = $this->account();
        $this->grant($owner, self::VIEW);
        $this->grant($other, self::VIEW);
        $first = $this->task(ownerAccountId: $owner);
        $second = $this->task(ownerAccountId: $owner);
        $this->task(ownerAccountId: $other);
        $page = $this->page($owner, 1);
        self::assertSame([$first->toRfc4122()], $this->ids($page));
        self::assertNotNull($page->next);
        $this->fails(fn () => $this->asAccount($other, fn () => $this->queries->ask(new ListTasksQuery($other, 1, $page->next))), InvalidTaskInput::class);
        self::assertSame([$second->toRfc4122()], $this->ids($this->page($owner, 1, $page->next)));

        $this->observer->executeStatement('DELETE FROM authenticating_account WHERE id = ?', [$owner->toRfc4122()]);
        $this->fails(fn () => $this->page($owner), AuthorizationDenied::class);
        self::assertSame([$first->toRfc4122(), $second->toRfc4122()], $this->cliTaskIds(['app:task:list', '--owner='.$owner, '--limit=100']));
    }

    /** @param list<string> $arguments
     * @return list<string>
     */
    private function cliTaskIds(array $arguments): array
    {
        $tasks = $this->json($this->cli($arguments))['tasks'] ?? null;
        self::assertIsArray($tasks);
        $ids = [];
        foreach ($tasks as $task) {
            self::assertIsArray($task);
            self::assertIsString($task['id'] ?? null);
            $ids[] = $task['id'];
        }

        return $ids;
    }
}
