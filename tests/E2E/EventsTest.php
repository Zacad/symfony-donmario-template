<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Fixtures\Events\EventsFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/** @phpstan-type EventProof array{kind: string, task: string, depth: int, label?: string, transaction?: string, managed?: int, committedRows?: int, localRows?: int, stackDepth?: int} */
final class EventsTest extends DatabaseTestCase
{
    private const string MIGRATION = 'App\\Module\\EventObserving\\Resources\\migrations\\Version20260912000100';

    private Connection $observer;
    private EventsFixture $fixture;
    private Process $server;
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->observer = $this->database();
        $this->prefix = 'events-'.bin2hex(random_bytes(8)).'-';
        self::assertNull($this->observer->fetchOne("SELECT to_regclass('public.event_observing_observation')"), 'Event fixtures must start without an observation table.');
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->server)) {
                $this->server->stop(2);
            }
            if (isset($this->observer)) {
                if (isset($this->fixture) && 1 === $this->observer->fetchOne('SELECT count(*) FROM doctrine_migration_versions WHERE version = ?', [self::MIGRATION])) {
                    $down = $this->fixture->console(['doctrine:migrations:execute', self::MIGRATION, '--down']);
                    self::assertSame(0, $down->getExitCode(), $down->getOutput().$down->getErrorOutput());
                }
                $this->observer->executeStatement('DELETE FROM task_tracking_task WHERE title LIKE ?', [$this->prefix.'%']);
                self::assertNull($this->observer->fetchOne("SELECT to_regclass('public.event_observing_observation')"), 'Fixture schema must be removed before the normal persistence/outage phases.');
            }
        } finally {
            if (isset($this->fixture)) {
                $this->fixture->remove();
            }
            parent::tearDown();
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function subscriberScenarios(): iterable
    {
        yield 'all independent listeners commit' => ['success', ['first', 'middle', 'last', 'child-first', 'child-middle', 'child-last']];
        yield 'only selected Domain facts become public events' => ['internal-fact', ['first', 'middle', 'last', 'child-first', 'child-middle', 'child-last']];
        yield 'actual subscriber SQL rolls back; following listeners recover' => ['listener-failure', ['first', 'last', 'child-first', 'child-last']];
        yield 'committed child survives listener later throwing' => ['late-failure', ['first', 'middle', 'last', 'child-first', 'child-middle', 'child-last']];
        yield 'two listener failures still retain committed children' => ['multiple-failure', ['first', 'last', 'child-first', 'child-last']];
        yield 'middle constructor fails; first and last still commit' => ['constructor-failure', ['first', 'last', 'child-first', 'child-last']];
    }

    /** @param list<string> $labels */
    #[DataProvider('subscriberScenarios')]
    public function testRealHttpAndCliSubscribersPreserveProducerSuccess(string $scenario, array $labels): void
    {
        $this->prepare($scenario);
        $http = HttpClient::createForBaseUri($this->startServer(), ['max_duration' => 15]);
        $title = $this->prefix."<error>unchanged</error>\n\x1b[31m";
        $response = $http->request('POST', '/_demo/tasks', ['json' => ['title' => $title]]);
        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $id = $response->toArray()['id'];
        self::assertIsString($id);
        self::assertTrue(Uuid::isValid($id));
        self::assertSame('/_demo/tasks/'.$id, $response->getHeaders()['location'][0]);
        self::assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0]);
        self::assertSame($title, $this->observer->fetchOne('SELECT title FROM task_tracking_task WHERE id = ?', [$id]));
        $this->assertDelivery($id, $labels, $scenario);

        $show = $this->fixture->console(['app:task:show', $id]);
        self::assertSame(0, $show->getExitCode(), $show->getErrorOutput());
        self::assertSame(['id' => $id, 'title' => $title], json_decode($show->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString("\x1b", $show->getOutput());

        $create = $this->fixture->console(['app:task:create', $this->prefix.'cli']);
        self::assertSame(0, $create->getExitCode(), $create->getErrorOutput());
        $cliId = trim($create->getOutput());
        self::assertTrue(Uuid::isValid($cliId), 'Subscriber diagnostics must not contaminate the returned UUID.');
        $this->assertDelivery($cliId, $labels, $scenario);
        self::assertSame(['id' => $cliId, 'title' => $this->prefix.'cli'], $http->request('GET', '/_demo/tasks/'.$cliId)->toArray());
        self::assertSame(422, $http->request('POST', '/_demo/tasks', ['json' => ['title' => '']])->getStatusCode());
        self::assertSame(2, $this->fixture->console(['app:task:create', ''])->getExitCode());

        $this->server->stop(2);
        $diagnostics = $this->server->getErrorOutput().$create->getErrorOutput();
        $this->assertSafeDiagnostics($diagnostics);
        if (!\in_array($scenario, ['success', 'internal-fact'], true)) {
            self::assertStringContainsString('event.delivery_failed', $diagnostics);
        }
        if ('multiple-failure' === $scenario) {
            self::assertGreaterThanOrEqual(2, substr_count($create->getErrorOutput(), 'event.delivery_failed'));
        }
        // A single compiled runtime also retains held buses/repositories/manager
        // through the same listener faults and then performs independent work.
        $this->assertScenario();
    }

    /** @return iterable<string, array{string}> */
    public static function transactionScenarios(): iterable
    {
        yield 'explicit required nested commands share commit' => ['required-success'];
        yield 'explicit root rollback discards all events' => ['root-rollback'];
        yield 'caught nested query failure discards all events' => ['nested-query'];
        yield 'deferred PostgreSQL commit failure delivers nothing' => ['commit-failure'];
        yield 'caught postFlush recording rejection rolls back actual SQL' => ['postflush-recording'];
        yield 'caught postFlush command rejection rolls back actual SQL' => ['postflush-command'];
        yield 'A B C FIFO' => ['fifo'];
        yield 'self-replenishing delivery budget and recovery' => ['budget'];
        yield 'recording buffer overflow preserves committed seed' => ['buffer'];
        yield 'zero-listener events count toward delivery budget' => ['zero-budget'];
    }

    #[DataProvider('transactionScenarios')]
    public function testRealPostgresqlTransactionsQueuesAndHeldReferenceRecovery(string $scenario): void
    {
        $this->prepare($scenario);
        $process = $this->assertScenario();
        if (\in_array($scenario, ['budget', 'buffer', 'zero-budget'], true)) {
            self::assertStringContainsString('event.overflow', $process->getErrorOutput());
        }
    }

    public function testNormalApplicationHasZeroSubscribers(): void
    {
        $this->prepare('success');
        $http = HttpClient::createForBaseUri('http://app:8080', ['max_duration' => 5]);
        $response = $http->request('POST', '/_demo/tasks', ['json' => ['title' => $this->prefix.'no-subscribers']]);
        self::assertSame(201, $response->getStatusCode());
        $id = $response->toArray()['id'];
        self::assertIsString($id);
        self::assertTrue(Uuid::isValid($id));
        self::assertSame($this->prefix.'no-subscribers', $this->observer->fetchOne('SELECT title FROM task_tracking_task WHERE id = ?', [$id]));
        self::assertSame(0, $this->observer->fetchOne('SELECT count(*) FROM event_observing_observation'));
        self::assertSame('', $this->fixture->readProbe(), 'The ordinary app must never load the fixture module.');
    }

    private function prepare(string $scenario): void
    {
        $this->fixture = new EventsFixture($scenario);
        $this->fixture->initialize();
        self::assertSame([], $this->fixture->sourceViolations(), 'Generated fixture source must pass the production source/contract rules.');
        $deptrac = $this->fixture->run([\dirname(__DIR__, 2).'/vendor/bin/deptrac', 'analyse', '--config-file='.$this->fixture->projectDir.'/deptrac.php', '--no-progress', '--report-uncovered', '--fail-on-uncovered']);
        self::assertSame(0, $deptrac->getExitCode(), $deptrac->getOutput().$deptrac->getErrorOutput());
        $migrate = $this->fixture->console(['doctrine:migrations:migrate']);
        self::assertSame(0, $migrate->getExitCode(), $migrate->getOutput().$migrate->getErrorOutput());
        $schema = $this->fixture->console(['app:architecture:check', '--database']);
        self::assertSame(0, $schema->getExitCode(), $schema->getOutput().$schema->getErrorOutput());
    }

    private function startServer(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->server = new Process([PHP_BINARY, '-S', $address, $this->fixture->projectDir.'/http.php'], $this->fixture->projectDir, timeout: 60);
        $this->server->start();
        self::assertTrue($this->server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'Development Server')), $this->server->getErrorOutput());
        self::assertSame(200, HttpClient::create()->request('GET', 'http://'.$address.'/health/ready', ['max_duration' => 15])->getStatusCode());

        return 'http://'.$address;
    }

    /** @param list<string> $labels */
    private function assertDelivery(string $id, array $labels, string $scenario): void
    {
        $stored = $this->observer->fetchFirstColumn('SELECT label FROM event_observing_observation WHERE task_id = ? ORDER BY label', [$id]);
        $sorted = $labels;
        sort($sorted);
        self::assertSame($sorted, $stored, 'Producer and successful subscriber writes must be committed on an independent connection.');
        $proof = $this->proofFor($id);
        $queries = array_values(array_filter($proof, static fn (array $row): bool => 'producer-query' === $row['kind']));
        self::assertNotEmpty($queries);
        self::assertSame(1, $queries[0]['committedRows'] ?? null, 'An independent owning-module connection sees the producer before the first listener command.');
        self::assertSame(0, $queries[0]['depth'], 'Listener entry query must run outside the producer transaction.');
        self::assertSame(0, $queries[0]['managed'] ?? null, 'Producer ORM state must be reset before listeners execute.');
        $adds = array_values(array_filter($proof, static fn (array $row): bool => 'producer-add' === $row['kind']));
        self::assertCount(1, $adds);
        self::assertSame(0, $adds[0]['committedRows'] ?? null);
        $observations = array_values(array_filter($proof, static fn (array $row): bool => 'observation' === $row['kind']));
        self::assertSame([1], array_values(array_unique(array_column($observations, 'depth'))));
        $transactions = array_column([$adds[0], ...$observations], 'transaction');
        self::assertCount(\count($transactions), array_unique($transactions), 'Each listener command owns an independent PostgreSQL root transaction.');
        if (\in_array($scenario, ['listener-failure', 'multiple-failure'], true)) {
            self::assertSame(['first', 'middle', 'last', 'child-first', 'child-last'], array_column($observations, 'label'));
            self::assertSame(1, $observations[1]['localRows'] ?? null, 'The failing command executed actual SQL before throwing.');
            self::assertSame(0, $observations[1]['committedRows'] ?? null);
        } elseif ('constructor-failure' === $scenario) {
            self::assertSame(['first', 'last', 'child-first', 'child-last'], array_column($observations, 'label'), 'A failed Middle constructor must neither invoke its command nor prevent Last or queued children from running.');
        } else {
            self::assertSame($labels, array_column($observations, 'label'), 'Committed listener events append FIFO and survive a later listener failure.');
        }
    }

    /** @return list<EventProof> */
    private function proofFor(string $id): array
    {
        $proof = [];
        foreach (explode("\n", trim($this->fixture->readProbe())) as $line) {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($row);
            if ($id === ($row['task'] ?? null)) {
                /** @var EventProof $entry */
                $entry = $row;
                $proof[] = $entry;
            }
        }

        return $proof;
    }

    private function assertScenario(): Process
    {
        $process = $this->fixture->run(['scenario.php', $this->prefix]);
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertTrue($result['verified']);
        self::assertSame($this->fixture->scenario, $result['scenario']);
        $this->assertSafeDiagnostics($process->getErrorOutput());

        return $process;
    }

    private function assertSafeDiagnostics(string $diagnostics): void
    {
        self::assertStringNotContainsString('EVENT_PRIVATE_FAILURE_CANARY', $diagnostics);
        self::assertStringNotContainsString($this->prefix, $diagnostics, 'Diagnostics cannot include task payload values.');
        self::assertStringNotContainsString('INSERT INTO', $diagnostics);
        $databaseUrl = getenv('DATABASE_URL');
        self::assertIsString($databaseUrl);
        self::assertFalse(str_contains($diagnostics, $databaseUrl), 'Diagnostics must not expose connection settings.');
    }
}
