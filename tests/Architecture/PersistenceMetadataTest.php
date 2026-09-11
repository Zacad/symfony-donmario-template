<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\Archiving\Domain\Archive;
use App\Module\Persisting\Domain\ForeignAssociation;
use App\Module\Persisting\Domain\ForeignEmbeddable;
use App\Module\Persisting\Domain\ForeignInheritance;
use App\Module\Persisting\Domain\ImplicitSchema;
use App\Module\Persisting\Domain\ImplicitTable;
use App\Module\Persisting\Domain\Record;
use App\Module\Persisting\Domain\TaggedRecord;
use App\Module\Persisting\Domain\WrongJoin;
use App\Module\Persisting\Domain\WrongJoinSchema;
use App\Module\Persisting\Domain\WrongRepository;
use App\Module\Persisting\Domain\WrongSchema;
use App\Module\Persisting\Domain\WrongTable;
use App\Module\Persisting\Infrastructure\OutsideDomain;
use App\Module\TaskTracking\Domain\Task;
use App\Platform\Architecture\CheckPersistenceCommand;
use App\Platform\Architecture\ModuleMap;
use App\Platform\Architecture\PersistenceBoundaries;
use App\Tests\Fixtures\Persistence\MetadataFixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PersistenceMetadataTest extends KernelTestCase
{
    public function testRealApplicationTaskAndCommandValidateWithoutConnecting(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertFalse($entityManager->getConnection()->isConnected());

        $guard = new PersistenceBoundaries($entityManager, new ModuleMap(\dirname(__DIR__, 2)));
        self::assertSame('TaskTracking', $guard->assertMetadata()['public.task_tracking_task'] ?? null);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        self::assertContains(Task::class, array_map(static fn (ClassMetadata $class): string => $class->name, $metadata));

        $command = new CommandTester(new CheckPersistenceCommand($guard));
        self::assertSame(Command::SUCCESS, $command->execute([]));
        self::assertStringContainsString('Persistence metadata and entity inventory passed (offline).', $command->getDisplay());
        self::assertFalse($entityManager->getConnection()->isConnected());
    }

    public function testRealAttributeMetadataAcceptsOwnedRepositoriesRelationshipsJoinTablesAndEmbeddedInheritance(): void
    {
        $fixture = new MetadataFixture();
        try {
            $association = $fixture->entityManager->getClassMetadata(Record::class)->associationMappings['tags'];
            self::assertSame(TaggedRecord::class, $association->declared);
            self::assertTrue(new \ReflectionProperty(TaggedRecord::class, 'tags')->isPrivate());
            $guard = new PersistenceBoundaries($fixture->entityManager, $fixture->moduleMap);
            self::assertSame([
                'public.archiving_archive' => 'Archiving',
                'public.persisting_record' => 'Persisting',
                'public.persisting_record_tag' => 'Persisting',
                'public.persisting_tag' => 'Persisting',
            ], $guard->assertMetadata($fixture->metadata(), $fixture->moduleMap));
            self::assertFalse($fixture->entityManager->getConnection()->isConnected());
        } finally {
            $fixture->unregister();
        }
    }

    /** @param class-string $class */
    #[DataProvider('invalidMappings')]
    public function testAttributeMetadataRejectsTheSpecificOwnershipViolation(string $scenario, string $class, string $diagnostic): void
    {
        $fixture = new MetadataFixture($scenario);
        try {
            // Metadata construction itself must succeed, before testing our guard.
            $metadata = $fixture->metadata($class);
            self::assertSame($class, $metadata[0]->name);
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage($diagnostic);
            (new PersistenceBoundaries($fixture->entityManager, $fixture->moduleMap))->assertMetadata($metadata);
        } finally {
            self::assertFalse($fixture->entityManager->getConnection()->isConnected());
            $fixture->unregister();
        }
    }

    /** @return iterable<string, array{string, class-string, string}> */
    public static function invalidMappings(): iterable
    {
        yield 'foreign repository' => [
            'WrongRepository', WrongRepository::class,
            'persistence.repository.owner: '.WrongRepository::class.' uses App\\Module\\Archiving\\Infrastructure\\ArchiveRepository outside Persisting.',
        ];
        yield 'foreign association' => [
            'ForeignAssociation', ForeignAssociation::class,
            'persistence.association.owner: '.ForeignAssociation::class.'::archive references '.Archive::class.' outside Persisting Domain.',
        ];
        yield 'foreign join table' => [
            'WrongJoin', WrongJoin::class,
            'persistence.join_table.owner: '.WrongJoin::class.'::tags maps public.archiving_stolen_join; expected an unquoted persisting_* identifier of at most 63 bytes.',
        ];
        yield 'foreign join schema' => [
            'WrongJoinSchema', WrongJoinSchema::class,
            'persistence.join_table.schema: '.WrongJoinSchema::class.'::tags maps persisting_wrong_join_schema in private; expected explicit public schema.',
        ];
        yield 'foreign mapped superclass' => [
            'ForeignInheritance', ForeignInheritance::class,
            'persistence.inheritance.owner: '.ForeignInheritance::class.' references App\\Module\\Archiving\\Domain\\ArchiveBase outside Persisting Domain.',
        ];
        yield 'foreign embeddable' => [
            'ForeignEmbeddable', ForeignEmbeddable::class,
            'persistence.embeddable.owner: '.ForeignEmbeddable::class.'::label references App\\Module\\Archiving\\Domain\\ArchiveLabel outside Persisting Domain.',
        ];
        yield 'wrong schema' => [
            'WrongSchema', WrongSchema::class,
            'persistence.table.schema: '.WrongSchema::class.' maps persisting_wrong_schema in private; expected explicit public schema.',
        ];
        yield 'wrong table owner' => [
            'WrongTable', WrongTable::class,
            'persistence.table.owner: '.WrongTable::class.' maps public.archiving_stolen; expected an unquoted persisting_* identifier of at most 63 bytes.',
        ];
        yield 'implicit table name' => [
            'ImplicitTable', ImplicitTable::class,
            'persistence.table.explicit: '.ImplicitTable::class.' requires an explicit #[ORM\\Table(name: ..., schema: "public")].',
        ];
        yield 'implicit schema' => [
            'ImplicitSchema', ImplicitSchema::class,
            'persistence.table.schema: '.ImplicitSchema::class.' maps persisting_implicit_schema in <implicit>; expected explicit public schema.',
        ];
        yield 'entity outside Domain' => [
            'OutsideDomain', OutsideDomain::class,
            'persistence.entity.owner: '.OutsideDomain::class.' must belong to a discovered module Domain namespace.',
        ];
    }

    public function testFilesystemInventoryRejectsAnOmittedMappingEvenWithOtherEntitiesMapped(): void
    {
        $fixture = new MetadataFixture();
        try {
            $fixture->entityManager->getConfiguration()->setMetadataDriverImpl(new AttributeDriver([
                $fixture->moduleMap->path('Persisting').'/Domain',
            ]));
            $metadata = $fixture->metadata();
            self::assertNotEmpty($metadata);
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('persistence.inventory.unmapped: '.Archive::class.' has #[ORM\\Entity] but is absent from the mapped entity list.');
            (new PersistenceBoundaries($fixture->entityManager, $fixture->moduleMap))->assertMetadata($metadata);
        } finally {
            self::assertFalse($fixture->entityManager->getConnection()->isConnected());
            $fixture->unregister();
        }
    }

    public function testEmptyMetadataIsNotSuccessfulValidation(): void
    {
        $fixture = new MetadataFixture();
        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('persistence.metadata.empty: no entities are mapped.');
            (new PersistenceBoundaries($fixture->entityManager, $fixture->moduleMap))->assertMetadata([]);
        } finally {
            self::assertFalse($fixture->entityManager->getConnection()->isConnected());
            $fixture->unregister();
        }
    }

    public function testCommandReturnsFailureWithAnOwnershipDiagnosticWithoutConnecting(): void
    {
        $fixture = new MetadataFixture('WrongTable');
        try {
            $command = new CommandTester(new CheckPersistenceCommand(new PersistenceBoundaries($fixture->entityManager, $fixture->moduleMap)));
            self::assertSame(Command::FAILURE, $command->execute([]));
            self::assertStringContainsString('persistence.table.owner:', $command->getDisplay());
            self::assertStringContainsString('public.archiving_stolen', $command->getDisplay());
            self::assertFalse($fixture->entityManager->getConnection()->isConnected());
        } finally {
            $fixture->unregister();
        }
    }
}
