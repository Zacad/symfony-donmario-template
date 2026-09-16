<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\TaskTracking\Domain\Task;
use App\Tests\Fixtures\Authenticating\Browser;
use App\Tests\Fixtures\Authorization\TaskBrowser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Uid\Uuid;

final class PersistenceReadTest extends RepositoryTestCase
{
    public function testCommittedDataSurvivesDatabaseContainerRecreation(): void
    {
        $this->database();
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $id = Uuid::fromString((new Filesystem())->readFile('var/e2e-task-id'));
        $task = $this->repository()->find($id);
        self::assertInstanceOf(Task::class, $task);
        self::assertSame($id->toRfc4122(), $task->id()->toRfc4122());
        self::assertSame('committed-before-recreation', $task->title());
        $response = HttpClient::create()->request('GET', 'http://app:8080/health/ready', ['max_duration' => 5]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
    }

    public function testHttpQueryReadsCommandDataAfterDatabaseRecovery(): void
    {
        $connection = $this->database();
        $id = new Filesystem()->readFile('var/e2e-cqrs-id');
        $state = json_decode(new Filesystem()->readFile('var/e2e-cqrs-session.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue(is_array($state) && is_string($state['account'] ?? null) && is_string($state['session'] ?? null), 'Expected private account/session phase marker.');
        $account = Uuid::fromString($state['account']);
        try {
            $browser = Browser::create($state['session']);
            $browser->request('GET', '/_demo/tasks/'.$id);
            self::assertSame(200, $browser->getResponse()->getStatusCode());
            self::assertSame(['id' => $id, 'title' => 'cqrs-before-recreation'], json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
            self::assertTrue(hash_equals($state['session'], Browser::session($browser)), 'The native session established before recreation is retained.');
            self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM task_tracking_task WHERE title = ?', ['outage-must-not-persist']));
        } finally {
            TaskBrowser::cleanup($connection, $account);
            new Filesystem()->remove('var/e2e-cqrs-session.json');
        }
    }
}
