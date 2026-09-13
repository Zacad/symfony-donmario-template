<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Fixtures\NativeEvents\NativeEventsFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

final class EventsTest extends DatabaseTestCase
{
    private Connection $observer;
    private NativeEventsFixture $fixture;
    /** @var list<Process> */
    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->observer = $this->database();
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->processes as $process) {
                $process->stop(1);
            }
            if (isset($this->fixture)) {
                $this->observer->executeQuery('SELECT pg_advisory_unlock_all()');
                $this->observer->executeStatement('ALTER TABLE platform_messaging_message DROP CONSTRAINT IF EXISTS native_reject_enqueue');
                $this->observer->executeStatement("DELETE FROM platform_messaging_message WHERE queue_name IN ('events', 'events_failed')");
                $this->fixture->mode('success');
                if (1 === $this->observer->fetchOne('SELECT count(*) FROM doctrine_migration_versions WHERE version = ?', [NativeEventsFixture::MIGRATION])) {
                    $this->successful($this->fixture->console(['doctrine:migrations:execute', NativeEventsFixture::MIGRATION, '--down']));
                }
                self::assertNull($this->observer->fetchOne("SELECT to_regclass('public.native_observing_observation')"));
            }
        } finally {
            if (isset($this->fixture)) {
                $this->fixture->remove();
            }
            parent::tearDown();
        }
    }

    #[Group('native-fixture')]
    public function testIdenticalCompiledHttpCliAndPayloadInBothModes(): void
    {
        $this->prepare();
        $http = HttpClient::createForBaseUri($this->server(), ['max_duration' => 15]);
        $httpId = Uuid::v7()->toRfc4122();
        self::assertSame(201, $http->request('POST', '/_native/'.$httpId)->getStatusCode());
        $cliId = $this->publish();
        if ($this->async()) {
            self::assertSame(2, $this->queueCount());
            self::assertSame(['producer'], $this->labels($httpId));
            self::assertSame(['producer'], $this->labels($cliId));
            $this->consume(2);
        }
        foreach ([$httpId, $cliId] as $id) {
            $this->assertEffects($id);
            $transactions = $this->observer->fetchFirstColumn('SELECT transaction FROM native_observing_observation WHERE id LIKE ?', [$id.'%']);
            $transactions = array_map(static function (mixed $transaction): string {
                self::assertIsString($transaction);

                return $transaction;
            }, $transactions);
            self::assertCount($this->async() ? 3 : 1, array_unique($transactions), 'Sync commands join producer; async listener commands own independent roots.');
        }
        self::assertSame(0, $this->queueCount());
        if (!$this->async()) {
            $this->fixture->mode('listener-failure');
            $failedId = Uuid::v7()->toRfc4122();
            self::assertSame(500, $http->request('POST', '/_native/'.$failedId)->getStatusCode());
            self::assertSame([], $this->labels($failedId));
            $failure = $this->fixture->console(['app:native:publish', $failedId]);
            self::assertNotSame(0, $failure->getExitCode());
            self::assertSame([], $this->labels($failedId));
            $attempts = $this->fixture->read('attempts.jsonl');
            self::assertStringContainsString('"local":1', $attempts, 'Failing subscriber performed SQL before rollback.');
        }
    }

    #[Group('native-fixture')]
    public function testCaughtFailuresLifecycleQueryAndHeldReferencesRecover(): void
    {
        $this->prepare();
        $held = $this->fixture->process(['held.php']);
        $held->run();
        $this->successful($held);
        self::assertSame(['rejected' => $this->async() ? 5 : 6, 'recovered' => true], json_decode($held->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('caught', $this->fixture->read('lifecycle-caught'));
        if ($this->async()) {
            self::assertSame(3, $this->queueCount());
            $this->consume(3);
        }
        self::assertSame($this->async() ? 9 : 12, $this->observer->fetchOne('SELECT count(*) FROM native_observing_observation'));
        self::assertSame(0, $this->queueCount());
    }

    #[Group('native-async')]
    public function testNativeEnqueueAndFlushAreAtomicAndCannotBeConsumedBeforeCommit(): void
    {
        $this->requireAsync();
        $this->prepare();
        $this->fixture->mode('precommit');
        $id = Uuid::v7()->toRfc4122();
        $this->lock();
        $producer = $this->start(['console.php', 'app:native:publish', $id]);
        $this->awaitBarrier($producer);
        self::assertSame([], $this->labels($id));
        self::assertSame(0, $this->queueCount());
        $proof = json_decode(trim($this->fixture->read('flush.jsonl')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($proof);
        self::assertIsArray($proof['jobs']);
        self::assertCount(1, $proof['jobs'], 'The producer connection sees its actual queue INSERT while independent SQL and the receiver cannot. PostgreSQL savepoints can assign the row a distinct xmin.');
        $emptyWorker = $this->fixture->process(['console.php', 'messenger:consume', 'events', '--time-limit=1', '--sleep=0.01', '--no-interaction']);
        $emptyWorker->run();
        $this->successful($emptyWorker);
        self::assertSame('', $this->fixture->read('attempts.jsonl'), 'An actual independent receiver cannot consume the uncommitted publication.');
        $this->unlock();
        $producer->wait();
        $this->successful($producer);
        self::assertSame(1, $this->queueCount(), 'One row per event, irrespective of listener count.');
        self::assertSame(['producer'], $this->labels($id));
        $this->fixture->mode('success');
        $this->consume(1);
        $this->assertEffects($id);

        foreach (['enqueue', 'flush'] as $fault) {
            $id = Uuid::v7()->toRfc4122();
            $table = 'enqueue' === $fault ? 'platform_messaging_message' : 'native_observing_observation';
            $check = 'enqueue' === $fault ? "queue_name <> 'events'" : "label <> 'producer'";
            $this->observer->executeStatement('ALTER TABLE '.$table.' ADD CONSTRAINT native_reject_'.$fault.' CHECK ('.$check.') NOT VALID');
            try {
                $failed = $this->fixture->console(['app:native:publish', $id]);
                self::assertNotSame(0, $failed->getExitCode(), 'Actual PostgreSQL '.$fault.' failure must reject the producer.');
                self::assertSame([], $this->labels($id));
                self::assertSame(0, $this->queueCount());
            } finally {
                $this->observer->executeStatement('ALTER TABLE '.$table.' DROP CONSTRAINT native_reject_'.$fault);
            }
        }
    }

    #[Group('native-async')]
    public function testProducerSigkillAndWorkerCrashBeforeAckAreRepeatSafe(): void
    {
        $this->requireAsync();
        $this->prepare();
        $this->fixture->mode('aftercommit');
        $id = Uuid::v7()->toRfc4122();
        $this->lock();
        $producer = $this->start(['console.php', 'app:native:publish', $id]);
        $this->awaitBarrier($producer);
        self::assertSame(['producer'], $this->labels($id));
        self::assertSame(1, $this->queueCount());
        $producer->signal(9);
        $producer->wait();
        $this->unlock();
        self::assertSame(1, $this->queueCount(), 'Committed publication survives producer SIGKILL.');

        $this->fixture->mode('before-ack');
        $this->lock();
        $worker = $this->fixture->worker(1);
        $this->processes[] = $worker;
        $this->awaitBarrier($worker);
        $this->assertEffects($id);
        self::assertSame(1, $this->observer->fetchOne("SELECT count(*) FROM platform_messaging_message WHERE queue_name = 'events' AND delivered_at IS NOT NULL"));
        $worker->signal(9);
        $worker->wait();
        $this->unlock();
        $this->fixture->mode('success');
        // Advance the native lease clock; do not wait five minutes or replace the receiver.
        $this->observer->executeStatement("UPDATE platform_messaging_message SET delivered_at = CURRENT_TIMESTAMP - INTERVAL '1 hour' WHERE queue_name = 'events'");
        $this->consume(1);
        $this->assertEffects($id);
        self::assertSame(4, substr_count($this->fixture->read('attempts.jsonl'), "\n"), 'Both listeners really ran again after missing ACK.');
        self::assertSame(0, $this->queueCount());
    }

    #[Group('native-async')]
    public function testCurrentListenersPartialSuccessNativeRetriesAndStandardOperatorRetry(): void
    {
        $this->requireAsync();
        $this->prepare();
        $id = $this->publish();
        // Publication does not freeze subscribers. Recompile after adding a third
        // ordinary listener, then consume the already-persisted native envelope.
        $listener = $this->fixture->read('src/Module/NativeObserving/Infrastructure/EventListener/SecondListener.php');
        $this->fixture->write('src/Module/NativeObserving/Infrastructure/EventListener/ThirdListener.php', str_replace(['SecondListener', "'second'"], ['ThirdListener', "'third'"], $listener));
        $this->fixture->clearCache();
        $this->fixture->mode('listener-failure');
        $this->consume(4);
        self::assertSame(['first', 'producer', 'third'], $this->labels($id));
        self::assertSame(0, $this->queueCount());
        self::assertSame(1, $this->queueCount('events_failed'));
        $attempts = $this->fixture->read('attempts.jsonl');
        self::assertSame(1, substr_count($attempts, '"label":"first"'), 'Native HandledStamp skips successful first handler on retries.');
        self::assertSame(1, substr_count($attempts, '"label":"third"'));
        self::assertSame(4, substr_count($attempts, '"label":"second"'));
        $failedId = $this->observer->fetchOne("SELECT id FROM platform_messaging_message WHERE queue_name = 'events_failed'");
        self::assertIsInt($failedId);
        $show = $this->fixture->console(['messenger:failed:show', (string) $failedId, '--transport=events_failed', '-vvv']);
        $this->successful($show);
        $this->safeDiagnostics($show->getOutput().$show->getErrorOutput());
        $this->fixture->mode('success');
        $retry = $this->fixture->console(['messenger:failed:retry', (string) $failedId, '--transport=events_failed', '--force', '-vvv']);
        $this->successful($retry);
        $this->safeDiagnostics($retry->getOutput().$retry->getErrorOutput());
        self::assertSame(['first', 'producer', 'second', 'third'], $this->labels($id), 'Standard operator retry may execute the remaining listener inline.');
        self::assertSame(0, $this->queueCount('events_failed'));
        self::assertSame(0, $this->queueCount());
    }

    #[Group('native-mode')]
    public function testComposeHttpAndCliUseSelectedMode(): void
    {
        $ids = [];
        try {
            $response = HttpClient::create()->request('POST', 'http://app:8080/_demo/tasks', ['json' => ['title' => 'native-mode-http'], 'max_duration' => 15]);
            self::assertSame(201, $response->getStatusCode());
            $id = $response->toArray()['id'];
            self::assertIsString($id);
            $ids[] = $id;
            $cli = new Process([PHP_BINARY, 'bin/console', 'app:task:create', 'native-mode-cli'], \dirname(__DIR__, 2));
            $cli->run();
            $this->successful($cli);
            $ids[] = trim($cli->getOutput());
            foreach ($ids as $id) {
                self::assertTrue(Uuid::isValid($id));
                self::assertSame(1, $this->observer->fetchOne('SELECT count(*) FROM task_tracking_task WHERE id = ?', [$id]));
                self::assertSame($this->async() ? 1 : 0, $this->observer->fetchOne("SELECT count(*) FROM platform_messaging_message WHERE queue_name = 'events' AND body LIKE ?", ['%'.$id.'%']));
            }
            if (!$this->async()) {
                $worker = new Process([PHP_BINARY, 'bin/console', 'messenger:consume', 'events', '--time-limit=1', '--no-interaction'], \dirname(__DIR__, 2), timeout: 10);
                $worker->run();
                self::assertSame(0, $worker->getExitCode());
                self::assertStringContainsString('transport ""', $worker->getOutput(), 'Symfony excludes SyncTransport from consumption. The bin/dev worker-start guard prevents launching an idle service in sync mode.');
                $this->safeDiagnostics($worker->getOutput().$worker->getErrorOutput());
            }
        } finally {
            foreach ($ids as $id) {
                $this->observer->executeStatement("DELETE FROM platform_messaging_message WHERE queue_name = 'events' AND body LIKE ?", ['%'.$id.'%']);
                $this->observer->executeStatement('DELETE FROM task_tracking_task WHERE id = ?', [$id]);
            }
        }
    }

    public function testSeedComposeWorker(): void
    {
        $this->seedPhase('worker');
    }

    public function testObserveComposeWorker(): void
    {
        $phase = $this->phase('worker');
        $deadline = microtime(true) + 20;
        while (0 !== $this->observer->fetchOne('SELECT count(*) FROM platform_messaging_message WHERE id = ?', [$phase['job']])) {
            self::assertLessThan($deadline, microtime(true), 'Compose worker must ACK the real public event with zero production subscribers.');
            usleep(20000);
        }
        $this->assertLegacy($phase);
        $this->cleanPhase($phase);
    }

    public function testSeedOutage(): void
    {
        $this->seedPhase('outage');
    }

    public function testRecoverOutage(): void
    {
        $phase = $this->phase('outage');
        self::assertSame($phase['wire'], $this->observer->fetchAssociative('SELECT body, headers FROM platform_messaging_message WHERE id = ?', [$phase['job']]), 'Database container recreation preserves the exact native event.');
        $this->assertLegacy($phase);
        $worker = new Process([PHP_BINARY, 'bin/console', 'messenger:consume', 'events', '--limit=1', '--time-limit=10', '--no-interaction'], \dirname(__DIR__, 2), timeout: 20);
        $worker->run();
        $this->successful($worker);
        self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM platform_messaging_message WHERE id = ?', [$phase['job']]));
        $this->assertLegacy($phase);
        $this->cleanPhase($phase);
    }

    private function prepare(): void
    {
        self::assertSame(0, $this->queueCount());
        self::assertSame(0, $this->queueCount('events_failed'));
        self::assertNull($this->observer->fetchOne("SELECT to_regclass('public.native_observing_observation')"));
        $this->fixture = new NativeEventsFixture();
        $this->fixture->initialize();
        self::assertSame([], $this->fixture->sourceViolations());
        $this->successful($this->fixture->console(['doctrine:migrations:migrate']));
        $this->successful($this->fixture->console(['app:architecture:check', '--database']));
    }

    private function publish(): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->successful($this->fixture->console(['app:native:publish', $id]));

        return $id;
    }

    private function consume(int $limit): void
    {
        $worker = $this->fixture->worker($limit);
        $this->processes[] = $worker;
        $worker->wait();
        $this->successful($worker);
        $this->safeDiagnostics($worker->getOutput().$worker->getErrorOutput());
    }

    private function successful(Process $process): void
    {
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    private function async(): bool
    {
        return 'doctrine://default' === getenv('EVENT_TRANSPORT_DSN');
    }

    private function requireAsync(): void
    {
        if (!$this->async()) {
            self::markTestSkipped('Native Doctrine transport phase.');
        }
    }

    private function queueCount(string $queue = 'events'): int
    {
        $count = $this->observer->fetchOne('SELECT count(*) FROM platform_messaging_message WHERE queue_name = ?', [$queue]);
        self::assertIsInt($count);

        return $count;
    }

    /** @return list<mixed> */
    private function labels(string $id): array
    {
        return $this->observer->fetchFirstColumn('SELECT label FROM native_observing_observation WHERE id LIKE ? ORDER BY label', [$id.'%']);
    }

    private function assertEffects(string $id): void
    {
        self::assertSame(['first', 'producer', 'second'], $this->labels($id));
        $payload = $this->observer->fetchOne('SELECT payload FROM native_observing_observation WHERE id = ?', [$id.':first']);
        self::assertIsString($payload);
        self::assertSame(['2026-09-13T12:34:56.123456+02:00', $id, "NATIVE_PRIVATE_PAYLOAD <error>\n雪", 7, true, null], json_decode($payload, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $arguments */
    private function start(array $arguments): Process
    {
        $process = $this->fixture->process($arguments);
        $this->processes[] = $process;
        $process->start();

        return $process;
    }

    private function lock(): void
    {
        $this->observer->executeQuery('SELECT pg_advisory_lock(?)', [NativeEventsFixture::LOCK]);
    }

    private function unlock(): void
    {
        $this->observer->executeQuery('SELECT pg_advisory_unlock(?)', [NativeEventsFixture::LOCK]);
    }

    private function awaitBarrier(Process $process): void
    {
        $deadline = microtime(true) + 15;
        do {
            if (1 === $this->observer->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND objid = ? AND NOT granted", [NativeEventsFixture::LOCK])) {
                return;
            }
            self::assertTrue($process->isRunning(), $process->getOutput().$process->getErrorOutput());
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Process did not reach the independently observed PostgreSQL lock barrier.');
    }

    private function server(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $server = $this->start(['-S', $address, $this->fixture->projectDir.'/http.php']);
        self::assertTrue($server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'Development Server')));

        return 'http://'.$address;
    }

    private function safeDiagnostics(string $output): void
    {
        foreach (['NATIVE_PRIVATE_PAYLOAD', 'NATIVE_PRIVATE_FAILURE', 'INSERT INTO secret'] as $canary) {
            self::assertStringNotContainsString($canary, $output);
        }
        $url = getenv('DATABASE_URL');
        self::assertIsString($url);
        self::assertStringNotContainsString($url, $output);
    }

    private function seedPhase(string $name): void
    {
        $this->requireAsync();
        self::assertSame(0, $this->queueCount());
        $create = new Process([PHP_BINARY, 'bin/console', 'app:task:create', 'native-'.$name], \dirname(__DIR__, 2));
        $create->run();
        $this->successful($create);
        $id = trim($create->getOutput());
        self::assertTrue(Uuid::isValid($id));
        $job = $this->observer->fetchAssociative("SELECT id, body, headers FROM platform_messaging_message WHERE queue_name = 'events'");
        self::assertIsArray($job);
        self::assertSame(1, $this->queueCount());
        $legacy = $this->observer->fetchOne("INSERT INTO platform_messaging_message (body, headers, queue_name, created_at, available_at) VALUES ('OPAQUE_LEGACY_DO_NOT_DECODE', '{}', 'durable_events', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) RETURNING id");
        file_put_contents(\dirname(__DIR__, 2).'/var/native-'.$name.'.json', json_encode(['task' => $id, 'job' => $job['id'], 'wire' => ['body' => $job['body'], 'headers' => $job['headers']], 'legacy' => $legacy], JSON_THROW_ON_ERROR));
    }

    /** @return array{task: string, job: int, wire: array{body: string, headers: string}, legacy: int} */
    private function phase(string $name): array
    {
        $contents = file_get_contents(\dirname(__DIR__, 2).'/var/native-'.$name.'.json');
        self::assertIsString($contents);
        /** @var array{task: string, job: int, wire: array{body: string, headers: string}, legacy: int} $phase */
        $phase = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $phase;
    }

    /** @param array{legacy: int, ...} $phase */
    private function assertLegacy(array $phase): void
    {
        self::assertSame(['body' => 'OPAQUE_LEGACY_DO_NOT_DECODE', 'headers' => '{}', 'queue_name' => 'durable_events', 'delivered_at' => null], $this->observer->fetchAssociative('SELECT body, headers, queue_name, delivered_at FROM platform_messaging_message WHERE id = ?', [$phase['legacy']]));
    }

    /** @param array{legacy: int, job: int, task: string, ...} $phase */
    private function cleanPhase(array $phase): void
    {
        $this->observer->executeStatement('DELETE FROM platform_messaging_message WHERE id IN (?, ?)', [$phase['legacy'], $phase['job']]);
        $this->observer->executeStatement('DELETE FROM task_tracking_task WHERE id = ?', [$phase['task']]);
    }
}
