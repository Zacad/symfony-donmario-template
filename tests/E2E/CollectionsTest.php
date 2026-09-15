<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Fixtures\Collections\CollectionsFixture;
use Symfony\Component\Process\Process;

final class CollectionsTest extends DatabaseTestCase
{
    public function testCompiledCollectionsValidateBeforeBeginAndInvalidResultsRollbackRealWrites(): void
    {
        $observer = $this->database();
        $fixture = new CollectionsFixture();
        $prefix = 'collections-'.bin2hex(random_bytes(8));
        try {
            $fixture->initialize();
            $this->successful($fixture->console(['doctrine:migrations:execute', CollectionsFixture::MIGRATION, '--up']));
            // This process has real PostgreSQL available, but malformed inputs
            // must still be rejected before the driver opens any transaction.
            $offline = $fixture->process(['offline.php']);
            $offline->run();
            $this->successful($offline);
            self::assertSame(['invalid_inputs' => 14, 'nonservices' => true, 'values' => true, 'query_recovered' => true], json_decode($offline->getOutput(), true, flags: JSON_THROW_ON_ERROR));

            $runtime = $fixture->process(['runtime.php', $prefix]);
            $runtime->run();
            $this->successful($runtime);
            self::assertSame(array_fill_keys(['invalid', 'caught-command', 'caught-query'], ['rolled_back' => true, 'recovered' => true]), json_decode($runtime->getOutput(), true, flags: JSON_THROW_ON_ERROR));
            self::assertSame(3, $observer->fetchOne('SELECT count(*) FROM task_tracking_task WHERE title LIKE ?', [$prefix.'%']));
            self::assertSame(4, $observer->fetchOne('SELECT count(*) FROM collection_checking_observation WHERE label LIKE ?', [$prefix.'%']));

            $attempts = $this->rows($fixture->read('observations.jsonl'));
            $byLabel = [];
            foreach ($attempts as $attempt) {
                self::assertSame(1, $attempt['local'], 'An actual immediate INSERT was SQL-visible before output validation.');
                self::assertIsString($attempt['label']);
                $byLabel[$attempt['label']] = $attempt;
            }
            foreach (['invalid', 'caught-command', 'caught-query'] as $mode) {
                $label = $prefix.'-'.$mode;
                self::assertArrayHasKey($label, $byLabel);
                self::assertSame(0, $observer->fetchOne('SELECT count(*) FROM collection_checking_observation WHERE label LIKE ?', [$label.'%']));
                self::assertSame(0, $observer->fetchOne('SELECT count(*) FROM task_tracking_task WHERE title LIKE ?', [$label.'%']));
                if ('invalid' !== $mode) {
                    self::assertArrayHasKey($label.'-caught', $byLabel, 'The outer handler really caught the nested output error and continued.');
                    self::assertSame($byLabel[$label]['transaction'], $byLabel[$label.'-caught']['transaction']);
                }
            }
            self::assertSame($byLabel[$prefix.'-caught-command']['transaction'], $byLabel[$prefix.'-caught-command-nested']['transaction'], 'The nested command joined the root PostgreSQL transaction.');
            $flushes = $this->rows($fixture->read('flush.jsonl'));
            $recoveryFlushes = 0;
            foreach ($flushes as $flush) {
                self::assertIsString($flush['label']);
                if ($prefix.'-void' === $flush['label']) {
                    continue;
                }
                ++$recoveryFlushes;
                self::assertStringStartsWith($prefix.'-recovery-', $flush['label']);
                self::assertTrue($flush['active']);
                self::assertSame(1, $flush['local_task'], 'Root flush materialized the nested command entity.');
                self::assertSame(1, $flush['local_observation']);
                self::assertSame(0, $flush['outside_task'], 'The independent connection cannot see the task before root commit.');
                self::assertSame(0, $flush['outside_observation']);
            }
            self::assertSame(3, $recoveryFlushes, 'Exactly one root flush for each successful recovery.');
        } finally {
            try {
                $observer->executeStatement('DELETE FROM task_tracking_task WHERE title LIKE ?', [$prefix.'%']);
                if (1 === $observer->fetchOne('SELECT count(*) FROM doctrine_migration_versions WHERE version = ?', [CollectionsFixture::MIGRATION])) {
                    $this->successful($fixture->console(['doctrine:migrations:execute', CollectionsFixture::MIGRATION, '--down']));
                }
            } finally {
                $fixture->remove();
            }
        }
    }

    private function successful(Process $process): void
    {
        self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
    }

    /** @return list<array<array-key, mixed>> */
    private function rows(string $contents): array
    {
        $rows = [];
        foreach (explode("\n", trim($contents)) as $line) {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($row);
            /* @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return $rows;
    }
}
