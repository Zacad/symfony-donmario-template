<?php

declare(strict_types=1);

namespace App\Platform\Architecture;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Tools\SchemaTool;

/** Verification-time ownership checks, not a runtime SQL sandbox. */
final readonly class PersistenceBoundaries
{
    private const string HISTORY_TABLE = 'public.doctrine_migration_versions';
    private const string TRANSPORT_TABLE = 'public.platform_messaging_message';

    public function __construct(private EntityManagerInterface $entityManager, private ModuleMap $moduleMap)
    {
    }

    /**
     * Does not introspect the database or construct a SchemaTool. Alternate metadata
     * must come from a real metadata factory; the map selects its filesystem inventory.
     *
     * @param list<ORM\ClassMetadata<object>>|null $metadata
     *
     * @return array<string, string> Exact schema-qualified table => module, including join tables
     */
    public function assertMetadata(?array $metadata = null, ?ModuleMap $moduleMap = null): array
    {
        $metadata ??= $this->entityManager->getMetadataFactory()->getAllMetadata();
        $moduleMap ??= $this->moduleMap;
        $modules = $moduleMap->modules();
        $entities = [];
        foreach ($metadata as $class) {
            if (!$class->isMappedSuperclass && !$class->isEmbeddedClass) {
                $entities[$class->name] = true;
            }
        }
        if ([] === $entities) {
            throw new \LogicException('persistence.metadata.empty: no entities are mapped.');
        }

        $tables = [];
        $claims = [];
        foreach ($metadata as $class) {
            $owner = ModuleMap::owner($class->name);
            if (null === $owner || !in_array($owner, $modules, true) || !str_starts_with($class->name, 'App\\Module\\'.$owner.'\\Domain\\')) {
                throw new \LogicException('persistence.entity.owner: '.$class->name.' must belong to a discovered module Domain namespace.');
            }
            if (null !== $class->customRepositoryClassName && $owner !== ModuleMap::owner($class->customRepositoryClassName)) {
                throw new \LogicException('persistence.repository.owner: '.$class->name.' uses '.$class->customRepositoryClassName.' outside '.$owner.'.');
            }

            $reflection = new \ReflectionClass($class->name);
            for ($parent = $reflection->getParentClass(); false !== $parent; $parent = $parent->getParentClass()) {
                if ([] !== $parent->getAttributes(ORM\Entity::class) || [] !== $parent->getAttributes(ORM\MappedSuperclass::class)) {
                    $this->assertRelatedOwner('inheritance', $class->name, $parent->getName(), $owner);
                }
            }
            foreach (array_merge($class->parentClasses, $class->subClasses, array_values($class->discriminatorMap)) as $relative) {
                $this->assertRelatedOwner('inheritance', $class->name, $relative, $owner);
            }
            foreach ($class->embeddedClasses as $field => $embedded) {
                $this->assertRelatedOwner('embeddable', $class->name.'::'.$field, $embedded->class, $owner);
            }

            if ($class->isMappedSuperclass || $class->isEmbeddedClass) {
                continue;
            }

            $tableClass = $class->isInheritanceTypeSingleTable() ? $class->rootEntityName : $class->name;
            $attributes = (new \ReflectionClass($tableClass))->getAttributes(ORM\Table::class);
            $table = [] === $attributes ? null : $attributes[0]->newInstance();
            if (null === $table || null === $table->name) {
                throw new \LogicException('persistence.table.explicit: '.$class->name.' requires an explicit #[ORM\\Table(name: ..., schema: "public")].');
            }
            $name = $this->tableName($class->name, $table->name, $table->schema, $owner, 'table');
            if ($table->name !== $class->getTableName() || $table->schema !== $class->getSchemaName()) {
                throw new \LogicException('persistence.table.mapping: '.$class->name.' metadata differs from its explicit table '.$name.'.');
            }
            $this->claimTable($tables, $claims, $name, $owner, $tableClass);

            foreach ($class->associationMappings as $field => $association) {
                $source = $class->name.'::'.$field;
                $this->assertRelatedOwner('association', $source, $association->targetEntity, $owner);
                if ($association instanceof ORM\ManyToManyOwningSideMapping) {
                    // Doctrine retains the declaring class for inherited private
                    // associations; ReflectionClass(child) cannot retrieve them.
                    $property = (new \ReflectionClass($association->declared ?? $class->name))->getProperty($field);
                    $joinAttributes = $property->getAttributes(ORM\JoinTable::class);
                    $join = [] === $joinAttributes ? null : $joinAttributes[0]->newInstance();
                    if (null === $join || null === $join->name) {
                        throw new \LogicException('persistence.join_table.explicit: '.$source.' requires an explicit #[ORM\\JoinTable].');
                    }
                    $joinName = $this->tableName($source, $join->name, $join->schema, $owner, 'join_table');
                    if ($join->name !== $association->joinTable->name || $join->schema !== $association->joinTable->schema) {
                        throw new \LogicException('persistence.join_table.mapping: '.$source.' metadata differs from its explicit table '.$joinName.'.');
                    }
                    $this->claimTable($tables, $claims, $joinName, $owner, $property->getDeclaringClass()->getName().'::'.$field);
                }
                if (!isset($entities[$association->targetEntity])) {
                    throw new \LogicException('persistence.association.unmapped: '.$source.' targets unmapped '.$association->targetEntity.'.');
                }
            }
        }

        foreach ($this->entityInventory($moduleMap) as $class) {
            if (!isset($entities[$class])) {
                throw new \LogicException('persistence.inventory.unmapped: '.$class.' has #[ORM\\Entity] but is absent from the mapped entity list.');
            }
        }
        ksort($tables);

        return $tables;
    }

    /**
     * Audits public ordinary/partitioned tables and their outgoing FKs. Other
     * schemas and standalone sequences are outside this ownership check's scope.
     * Public views, materialized views and foreign tables are explicitly unsupported.
     *
     * @param list<ORM\ClassMetadata<object>>|null $metadata
     */
    public function assertDatabase(?array $metadata = null, ?ModuleMap $moduleMap = null): void
    {
        $metadata ??= $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tables = $this->assertMetadata($metadata, $moduleMap);
        $connection = $this->entityManager->getConnection();
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new \LogicException('persistence.database.platform: PostgreSQL is required.');
        }

        // pg_catalog queries intentionally bypass Doctrine's configured asset filter.
        $objects = $connection->fetchAllAssociative(<<<'SQL'
            SELECT n.nspname || '.' || c.relname AS name, c.relkind AS kind, c.relpersistence AS persistence
            FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
            ORDER BY c.relname
            SQL);
        $actual = [];
        foreach ($objects as $object) {
            $name = $this->catalogString($object, 'name');
            $kind = $this->catalogString($object, 'kind');
            if (!in_array($kind, ['r', 'p'], true)) {
                throw new \LogicException('persistence.database.unsupported: '.$name.' has unsupported relation kind '.$kind.'.');
            }
            if (self::TRANSPORT_TABLE === $name && 'p' !== $this->catalogString($object, 'persistence')) {
                throw new \LogicException('persistence.database.transport_durability: '.$name.' must be a permanent logged table.');
            }
            if (!isset($tables[$name]) && self::HISTORY_TABLE !== $name && self::TRANSPORT_TABLE !== $name) {
                throw new \LogicException('persistence.database.unexpected_table: '.$name.' has no mapped owner.');
            }
            if (self::TRANSPORT_TABLE === $name && 'r' !== $kind) {
                throw new \LogicException('persistence.database.transport_schema: '.self::TRANSPORT_TABLE.' must be an ordinary table.');
            }
            $actual[$name] = true;
        }
        foreach ($tables as $name => $owner) {
            if (!isset($actual[$name])) {
                throw new \LogicException('persistence.database.missing_table: '.$name.' owned by '.$owner.' is missing.');
            }
        }
        if (!isset($actual[self::TRANSPORT_TABLE])) {
            throw new \LogicException('persistence.database.missing_table: '.self::TRANSPORT_TABLE.' owned by Platform Messaging is missing.');
        }

        $foreignKeys = $connection->fetchAllAssociative(<<<'SQL'
            SELECT fk.conname AS name,
                   sn.nspname || '.' || s.relname AS source,
                   tn.nspname || '.' || t.relname AS target
            FROM pg_catalog.pg_constraint fk
            JOIN pg_catalog.pg_class s ON s.oid = fk.conrelid
            JOIN pg_catalog.pg_namespace sn ON sn.oid = s.relnamespace
            JOIN pg_catalog.pg_class t ON t.oid = fk.confrelid
            JOIN pg_catalog.pg_namespace tn ON tn.oid = t.relnamespace
            WHERE fk.contype = 'f' AND sn.nspname = 'public'
            ORDER BY sn.nspname, s.relname, fk.conname
            SQL);
        foreach ($foreignKeys as $foreignKey) {
            $name = $this->catalogString($foreignKey, 'name');
            $source = $this->catalogString($foreignKey, 'source');
            $target = $this->catalogString($foreignKey, 'target');
            $sourceOwner = $tables[$source] ?? null;
            $targetOwner = $tables[$target] ?? null;
            if (null === $sourceOwner || null === $targetOwner) {
                throw new \LogicException('persistence.database.foreign_key.unowned: '.$name.' links '.$source.' to '.$target.' without two mapped owners.');
            }
            if ($sourceOwner !== $targetOwner) {
                throw new \LogicException('persistence.database.foreign_key.cross_module: '.$name.' links '.$source.' ('.$sourceOwner.') to '.$target.' ('.$targetOwner.').');
            }
        }

        $this->assertTransportSchema($connection);

        $configuration = $connection->getConfiguration();
        $filter = $configuration->getSchemaAssetsFilter();
        $configuration->setSchemaAssetsFilter(static fn (): bool => true);
        try {
            $expectedSchema = (new SchemaTool($this->entityManager))->getSchemaFromMetadata($metadata);
            $manager = $connection->createSchemaManager();
            $comparator = $manager->createComparator((new ComparatorConfig())->withReportModifiedIndexes(false));
            foreach ($tables as $name => $owner) {
                $expectedTable = $expectedSchema->getTable($name);
                $tableName = substr($name, \strlen('public.'));
                \assert('' !== $tableName);
                $actualTable = $manager->introspectTableByUnquotedName($tableName, 'public');
                foreach ($expectedTable->getColumns() as $column) {
                    if (!$actualTable->hasColumn($column->getName())) {
                        throw new \LogicException('persistence.database.missing_column: '.$name.'.'.$column->getName().' owned by '.$owner.' is missing.');
                    }
                }
                if (!$comparator->compareTables($actualTable, $expectedTable)->isEmpty()) {
                    // Do not include SQL/default expressions: schema defaults may contain secrets.
                    throw new \LogicException('persistence.database.schema_drift: '.$name.' differs from Doctrine metadata.');
                }
            }
        } finally {
            $configuration->setSchemaAssetsFilter($filter);
        }
    }

    /** The technical transport has its own exact schema, never business metadata ownership. */
    private function assertTransportSchema(Connection $connection): void
    {
        $columns = $connection->fetchAllAssociative(<<<'SQL'
            SELECT a.attname AS name, pg_catalog.format_type(a.atttypid, a.atttypmod) AS type,
                   CASE WHEN a.attnotnull THEN 'required' ELSE 'nullable' END AS nullability,
                   a.attidentity::text AS identity, a.attgenerated::text AS generated,
                   CASE WHEN d.oid IS NULL OR (
                       a.attname = 'delivered_at' AND pg_catalog.pg_get_expr(d.adbin, d.adrelid) IN (
                           'NULL::timestamp without time zone', 'NULL::timestamp(0) without time zone', 'NULL'
                       )
                   ) THEN 'none' ELSE 'unexpected' END AS default_value
            FROM pg_catalog.pg_attribute a
            LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
            WHERE a.attrelid = 'public.platform_messaging_message'::regclass
              AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY a.attname
            SQL);
        $expected = [
            'available_at' => ['timestamp(0) without time zone', 'required', ''],
            'body' => ['text', 'required', ''],
            'created_at' => ['timestamp(0) without time zone', 'required', ''],
            'delivered_at' => ['timestamp(0) without time zone', 'nullable', ''],
            'headers' => ['text', 'required', ''],
            'id' => ['bigint', 'required', 'd'],
            'queue_name' => ['character varying(190)', 'required', ''],
        ];
        $expectedColumns = [];
        foreach ($expected as $name => [$type, $nullability, $identity]) {
            $expectedColumns[] = ['name' => $name, 'type' => $type, 'nullability' => $nullability, 'identity' => $identity, 'generated' => '', 'default_value' => 'none'];
        }
        if ($columns !== $expectedColumns) {
            // Never expose actual default expressions or persisted data in diagnostics.
            throw new \LogicException('persistence.database.transport_columns: '.self::TRANSPORT_TABLE.' differs from the approved transport columns.');
        }

        $indexes = $connection->fetchAllAssociative(<<<'SQL'
            SELECT pg_catalog.pg_get_indexdef(i.indexrelid) AS definition,
                   CASE WHEN i.indisprimary THEN 'primary' ELSE 'secondary' END AS kind,
                   CASE WHEN i.indisvalid AND i.indisready AND i.indislive AND i.indimmediate
                             AND i.indpred IS NULL AND i.indexprs IS NULL
                        THEN 'usable' ELSE 'unusable' END AS state
            FROM pg_catalog.pg_index i
            WHERE i.indrelid = 'public.platform_messaging_message'::regclass
            ORDER BY i.indisprimary DESC
            SQL);
        if ($indexes !== [
            ['definition' => 'CREATE UNIQUE INDEX platform_messaging_message_pkey ON public.platform_messaging_message USING btree (id)', 'kind' => 'primary', 'state' => 'usable'],
            ['definition' => 'CREATE INDEX platform_messaging_message_queue_idx ON public.platform_messaging_message USING btree (queue_name, available_at, delivered_at, id)', 'kind' => 'secondary', 'state' => 'usable'],
        ]) {
            throw new \LogicException('persistence.database.transport_indexes: '.self::TRANSPORT_TABLE.' differs from the approved transport indexes.');
        }
    }

    /** @return list<class-string> */
    private function entityInventory(ModuleMap $moduleMap): array
    {
        $entities = [];
        foreach ($moduleMap->modules() as $module) {
            $directory = $moduleMap->path($module).'/Domain';
            if (!is_dir($directory)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || 'php' !== $file->getExtension()) {
                    continue;
                }
                $relative = substr($file->getPathname(), \strlen($directory) + 1, -4);
                $class = 'App\\Module\\'.$module.'\\Domain\\'.str_replace('/', '\\', $relative);
                if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
                    throw new \LogicException('persistence.inventory.class: '.$file->getPathname().' must autoload '.$class.'.');
                }
                $reflection = new \ReflectionClass($class);
                if ($file->getRealPath() !== $reflection->getFileName()) {
                    throw new \LogicException('persistence.inventory.path: '.$class.' is not declared in '.$file->getPathname().'.');
                }
                if ([] !== $reflection->getAttributes(ORM\Entity::class)) {
                    $entities[] = $class;
                }
            }
        }
        sort($entities);

        return $entities;
    }

    private function assertRelatedOwner(string $rule, string $source, string $target, string $owner): void
    {
        if ($owner !== ModuleMap::owner($target) || !str_starts_with($target, 'App\\Module\\'.$owner.'\\Domain\\')) {
            throw new \LogicException('persistence.'.$rule.'.owner: '.$source.' references '.$target.' outside '.$owner.' Domain.');
        }
    }

    private function tableName(string $class, string $name, ?string $schema, string $owner, string $rule): string
    {
        if ('public' !== $schema) {
            throw new \LogicException('persistence.'.$rule.'.schema: '.$class.' maps '.$name.' in '.($schema ?? '<implicit>').'; expected explicit public schema.');
        }
        $prefix = ModuleMap::prefix($owner);
        if (!preg_match('/^'.preg_quote($prefix, '/').'[a-z0-9_]+$/D', $name) || \strlen($name) > 63) {
            throw new \LogicException('persistence.'.$rule.'.owner: '.$class.' maps public.'.$name.'; expected an unquoted '.$prefix.'* identifier of at most 63 bytes.');
        }

        return 'public.'.$name;
    }

    /**
     * @param array<string, string> $tables
     * @param array<string, string> $claims
     */
    private function claimTable(array &$tables, array &$claims, string $name, string $owner, string $claim): void
    {
        if (isset($claims[$name]) && $claims[$name] !== $claim) {
            throw new \LogicException('persistence.table.shared: '.$name.' is claimed by '.$claims[$name].' and '.$claim.'.');
        }
        $tables[$name] = $owner;
        $claims[$name] = $claim;
    }

    /** @param array<string, mixed> $row */
    private function catalogString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value)) {
            throw new \LogicException('persistence.database.catalog: expected a string '.$key.'.');
        }

        return $value;
    }
}
