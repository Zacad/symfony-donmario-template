<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Platform\Persistence\MigrationPreflight;
use App\Tests\Fixtures\Migrations\MigrationFixture;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class MigrationInventoryTest extends TestCase
{
    public function testRealDirectoryDiscoveryAndChronologicalOrderingNeedNoConnection(): void
    {
        $fixture = new MigrationFixture();
        try {
            $later = $fixture->addMigration('ARecording', 'Version20310102000000');
            $earlier = $fixture->addMigration('ZPreparing', 'Version20310101000000');
            $factory = $fixture->factory();
            self::assertFalse($factory->getConnection()->isConnected());
            $fixture->inventory($factory)->assertValid();

            $migrations = $factory->getMigrationPlanCalculator()->getMigrations()->getItems();
            self::assertSame([$earlier, $later], array_values(array_map(
                static fn (AvailableMigration $migration): string => (string) $migration->getVersion(),
                $migrations,
            )));
            foreach ($migrations as $migration) {
                self::assertSame(realpath($fixture->file((string) $migration->getVersion())), (new \ReflectionObject($migration->getMigration()))->getFileName());
            }
            self::assertFalse($factory->getConnection()->isConnected());
        } finally {
            $fixture->cleanup();
        }
    }

    public function testDuplicateTimestampsAcrossNamespacesNameBothMigrations(): void
    {
        $fixture = new MigrationFixture();
        try {
            $first = $fixture->addMigration('ADuplicating', 'Version20320229000000');
            $second = $fixture->addMigration('ZDuplicating', 'Version20320229000000');
            $this->assertInvalid($fixture, $fixture->factory(), 'migration.duplicate: '.$second.' shares a timestamp with '.$first.'.');
        } finally {
            $fixture->cleanup();
        }
    }

    #[DataProvider('malformedVersions')]
    public function testMalformedVersionsHavePreciseDiagnostics(string $version, string $diagnostic): void
    {
        $fixture = new MigrationFixture();
        try {
            $class = $fixture->addMigration('Dating', $version);
            $this->assertInvalid($fixture, $fixture->factory(), 'migration.version: '.$class.' '.$diagnostic);
        } finally {
            $fixture->cleanup();
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedVersions(): iterable
    {
        yield 'short timestamp' => ['Version20330101', 'requires VersionYYYYMMDDHHMMSS.'];
        yield 'trailing suffix' => ['Version20330101000000Extra', 'requires VersionYYYYMMDDHHMMSS.'];
        yield 'non-leap February 29' => ['Version20330229000000', 'has an invalid UTC timestamp.'];
        yield 'February 30' => ['Version20330230000000', 'has an invalid UTC timestamp.'];
        yield 'invalid month' => ['Version20331301000000', 'has an invalid UTC timestamp.'];
        yield 'invalid hour' => ['Version20330101240000', 'has an invalid UTC timestamp.'];
    }

    public function testAnOmittedDirectoryFailsEvenWhenAnotherModuleIsRegistered(): void
    {
        $fixture = new MigrationFixture();
        try {
            $fixture->addMigration('Registering', 'Version20340101000000');
            $missing = $fixture->addMigration('Omitting', 'Version20340102000000');
            $factory = $fixture->factory(directories: array_slice($fixture->directories(), 0, 1, true));
            self::assertCount(1, $factory->getMigrationRepository()->getMigrations());
            $this->assertInvalid($fixture, $factory, 'migration.inventory.unregistered: '.$missing.' is absent from configured migration paths.');
        } finally {
            $fixture->cleanup();
        }
    }

    public function testAConfiguredMigrationOutsideTheModuleInventoryIsRejected(): void
    {
        $fixture = new MigrationFixture();
        $foreign = new MigrationFixture();
        try {
            $fixture->addMigration('Owning', 'Version20350101000000');
            $unowned = $foreign->addMigration('Invading', 'Version20350102000000');
            $factory = $fixture->factory(directories: $fixture->directories() + $foreign->directories());
            self::assertCount(2, $factory->getMigrationRepository()->getMigrations());
            $this->assertInvalid($fixture, $factory, 'migration.inventory.unowned: '.$unowned.' does not match an owned migration file.');
        } finally {
            $fixture->cleanup();
            $foreign->cleanup();
        }
    }

    public function testMatchingClassNameDoesNotPermitLoadingADifferentPhysicalFile(): void
    {
        $fixture = new MigrationFixture();
        try {
            $class = $fixture->addMigration('Relocating', 'Version20360101000000');
            $external = $fixture->modules->projectDir.'/external';
            (new Filesystem())->copy($fixture->file($class), $external.'/'.basename($fixture->file($class)));
            $directories = array_map(static fn (string $directory): string => $external, $fixture->directories());
            $factory = $fixture->factory(directories: $directories);
            self::assertCount(1, $factory->getMigrationRepository()->getMigrations());
            $this->assertInvalid($fixture, $factory, 'migration.inventory.unowned: '.$class.' does not match an owned migration file.');
        } finally {
            $fixture->cleanup();
        }
    }

    public function testNontransactionalMigrationIsRejectedDespiteTransactionalConfiguration(): void
    {
        $fixture = new MigrationFixture();
        try {
            $class = $fixture->addMigration('Committing', 'Version20370101000000', transactional: false);
            $factory = $fixture->factory();
            self::assertTrue($factory->getConfiguration()->isTransactional());
            $this->assertInvalid($fixture, $factory, 'migration.transaction: '.$class.' must be transactional.');
        } finally {
            $fixture->cleanup();
        }
    }

    public function testAnEmptyInventoryCannotPass(): void
    {
        $fixture = new MigrationFixture();
        try {
            $this->assertInvalid($fixture, $fixture->factory(), 'migration.inventory.empty: no owned migrations found.');
        } finally {
            $fixture->cleanup();
        }
    }

    public function testNestedMigrationCannotDisappearFromPreflightInventory(): void
    {
        $fixture = new MigrationFixture();
        try {
            $class = $fixture->addMigration('Nesting', 'Version20380101000000');
            $file = $fixture->file($class);
            $nested = dirname($file).'/nested/'.basename($file);
            $filesystem = new Filesystem();
            $filesystem->mkdir(dirname($nested));
            $filesystem->rename($file, $nested);
            $this->assertInvalid($fixture, $fixture->factory(), 'migration.layout: '.$nested.' must be directly inside its owned migration directory.');
        } finally {
            $fixture->cleanup();
        }
    }

    public function testWrongHostIsRejectedBeforeAnyConnectionAttempt(): void
    {
        $fixture = new MigrationFixture();
        try {
            $connection = $fixture->factory()->getConnection();
            self::assertFalse($connection->isConnected());
            try {
                MigrationPreflight::assertTestDatabase($connection);
                self::fail('Expected the target guard to reject the offline, non-database host.');
            } catch (\LogicException $exception) {
                self::assertSame('migration.target: tests require the isolated database/app_test connection as app.', $exception->getMessage());
            }
            self::assertFalse($connection->isConnected());
        } finally {
            $fixture->cleanup();
        }
    }

    public function testExactPlatformDirectoryParticipatesInChronologicalInventoryWithoutConnecting(): void
    {
        $fixture = new MigrationFixture();
        try {
            $later = $fixture->addMigration('Recording', 'Version20500102000000');
            $platform = $this->platformMigration($fixture, 'Version20500101000000');
            $factory = $fixture->factory(directories: $fixture->directories() + $platform);
            $fixture->inventory($factory)->assertValid();
            self::assertSame([
                'App\\Platform\\Messaging\\Resources\\migrations\\Version20500101000000',
                $later,
            ], array_values(array_map(
                static fn (AvailableMigration $migration): string => (string) $migration->getVersion(),
                $factory->getMigrationPlanCalculator()->getMigrations()->getItems(),
            )));
            self::assertFalse($factory->getConnection()->isConnected());
        } finally {
            $fixture->cleanup();
        }
    }

    public function testUnknownTechnicalNamespaceDoesNotGainMigrationOwnership(): void
    {
        $fixture = new MigrationFixture();
        try {
            $fixture->addMigration('Owning', 'Version20510101000000');
            $foreign = $this->platformMigration($fixture, 'Version20510102000000', responsibility: 'Other');
            $factory = $fixture->factory(directories: $fixture->directories() + $foreign);
            $this->assertInvalid($fixture, $factory, 'migration.inventory.unowned: App\\Platform\\Other\\Resources\\migrations\\Version20510102000000 does not match an owned migration file.');
        } finally {
            $fixture->cleanup();
        }
    }

    public function testPlatformMigrationMustBeRegistered(): void
    {
        $fixture = new MigrationFixture();
        try {
            $fixture->addMigration('Registering', 'Version20520101000000');
            $this->platformMigration($fixture, 'Version20520102000000');
            $this->assertInvalid($fixture, $fixture->factory(), 'migration.inventory.unregistered: App\\Platform\\Messaging\\Resources\\migrations\\Version20520102000000 is absent from configured migration paths.');
        } finally {
            $fixture->cleanup();
        }
    }

    public function testPlatformAndModuleTimestampsShareTheSameUniquenessCheck(): void
    {
        $fixture = new MigrationFixture();
        try {
            $module = $fixture->addMigration('Duplicating', 'Version20530101000000');
            $platform = $this->platformMigration($fixture, 'Version20530101000000');
            $factory = $fixture->factory(directories: $fixture->directories() + $platform);
            $this->assertInvalid($fixture, $factory, 'migration.duplicate: '.$module.' shares a timestamp with App\\Platform\\Messaging\\Resources\\migrations\\Version20530101000000.');
        } finally {
            $fixture->cleanup();
        }
    }

    public function testPlatformNamespaceCannotSubstituteADifferentPhysicalPath(): void
    {
        $fixture = new MigrationFixture();
        try {
            $platform = $this->platformMigration($fixture, 'Version20540101000000');
            $external = $fixture->modules->projectDir.'/external';
            (new Filesystem())->copy(array_values($platform)[0].'/Version20540101000000.php', $external.'/Version20540101000000.php');
            $factory = $fixture->factory(directories: array_map(static fn (): string => $external, $platform));
            $this->assertInvalid($fixture, $factory, 'migration.inventory.unowned: App\\Platform\\Messaging\\Resources\\migrations\\Version20540101000000 does not match an owned migration file.');
        } finally {
            $fixture->cleanup();
        }
    }

    public function testPlatformMigrationMustRemainTransactional(): void
    {
        $fixture = new MigrationFixture();
        try {
            $platform = $this->platformMigration($fixture, 'Version20550101000000', transactional: false);
            $this->assertInvalid($fixture, $fixture->factory(directories: $platform), 'migration.transaction: App\\Platform\\Messaging\\Resources\\migrations\\Version20550101000000 must be transactional.');
        } finally {
            $fixture->cleanup();
        }
    }

    /** @return array<string, string> */
    private function platformMigration(MigrationFixture $fixture, string $version, string $responsibility = 'Messaging', bool $transactional = true): array
    {
        $namespace = 'App\\Platform\\'.$responsibility.'\\Resources\\migrations';
        $directory = $fixture->modules->projectDir.'/src/Platform/'.$responsibility.'/Resources/migrations';
        $filesystem = new Filesystem();
        $source = strtr($filesystem->readFile(__DIR__.'/../Fixtures/Migrations/migration.php.fixture'), [
            '{{namespace}}' => $namespace,
            '{{version}}' => $version,
            '{{transactional}}' => $transactional ? 'true' : 'false',
            '{{statements}}' => '[]',
        ]);
        $filesystem->dumpFile($directory.'/'.$version.'.php', $source);

        return [$namespace => $directory];
    }

    private function assertInvalid(MigrationFixture $fixture, DependencyFactory $factory, string $diagnostic): void
    {
        self::assertFalse($factory->getConnection()->isConnected());
        try {
            $fixture->inventory($factory)->assertValid();
            self::fail('Expected inventory violation: '.$diagnostic);
        } catch (\LogicException $exception) {
            self::assertSame($diagnostic, $exception->getMessage());
        } finally {
            self::assertFalse($factory->getConnection()->isConnected());
        }
    }
}
