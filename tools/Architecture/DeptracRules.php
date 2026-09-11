<?php

declare(strict_types=1);

namespace App\Tools\Architecture;

use App\Platform\Architecture\ContractTypes;
use App\Platform\Architecture\ModuleMap;
use Deptrac\Deptrac\Contract\Config\AnalyserConfig;
use Deptrac\Deptrac\Contract\Config\Collector\BoolConfig;
use Deptrac\Deptrac\Contract\Config\Collector\ClassNameRegexConfig;
use Deptrac\Deptrac\Contract\Config\CollectorConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\EmitterType;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

final class DeptracRules
{
    /** The same configuration builder is used by the application and disposable fixtures. */
    public static function configure(DeptracConfig $config, string $projectDir): void
    {
        $values = self::layer('ImmutableValues', ContractTypes::IMMUTABLE_PATTERN);
        // Exact mapping declaration types from ORM 3.7, plus the namespace import
        // used by "use Doctrine\ORM\Mapping as ORM". Metadata factories/drivers
        // in the same namespace are runtime persistence dependencies.
        $attributes = 'AssociationOverride|AssociationOverrides|AttributeOverride|AttributeOverrides|Cache|ChangeTrackingPolicy|Column|CustomIdGenerator|DiscriminatorColumn|DiscriminatorMap|Embeddable|Embedded|Entity|EntityListeners|GeneratedValue|HasLifecycleCallbacks|Id|Index|InheritanceType|InverseJoinColumn|JoinColumn|JoinTable|ManyToMany|ManyToOne|MappedSuperclass|OneToMany|OneToOne|OrderBy|PostLoad|PostPersist|PostRemove|PostUpdate|PreFlush|PrePersist|PreRemove|PreUpdate|SequenceGenerator|Table|UniqueConstraint|Version';
        $mappingPattern = '~^(?:Doctrine\\\\ORM\\\\Mapping(?:\\\\(?:'.$attributes.'))?|Symfony\\\\Bridge\\\\Doctrine\\\\Types\\\\UuidType)$~Di';
        $persistencePattern = '~^(?:Doctrine\\\\|Symfony\\\\Bridge\\\\Doctrine\\\\|PDO(?:Statement)?$)~i';
        $mapping = self::layer('PersistenceMapping', $mappingPattern);
        $persistence = Layer::withName('PersistenceRuntime')->collectors(BoolConfig::create(
            [self::collector($persistencePattern)],
            [self::collector($mappingPattern)],
        ));
        $vendor = Layer::withName('Vendor')->collectors(BoolConfig::create(
            [self::collector('~^(?!App(?:\\\\|$)).+~i')],
            [self::collector(ContractTypes::IMMUTABLE_PATTERN), self::collector($persistencePattern)],
        ));
        $platform = self::layer('Platform', '~^App\\\\Platform\\\\~i');
        $kernel = self::layer('KernelBoot', '~^App\\\\Kernel$~Di');
        $classified = [
            self::collector('~^App\\\\Platform\\\\~i'),
            self::collector('~^App\\\\Kernel$~Di'),
        ];
        $applicationData = $events = $internals = $domains = $applications = [];
        foreach ((new ModuleMap($projectDir))->modules() as $module) {
            $prefix = '~^'.preg_quote('App\\Module\\'.$module.'\\', '~');
            $data = self::collector(ContractTypes::applicationPattern($module).'i');
            $event = self::collector(ContractTypes::eventPattern($module).'i');
            $domain = self::collector($prefix.'Domain\\\\~i');
            $application = self::collector($prefix.'Application\\\\~i');
            $all = self::collector($prefix.'~i');
            $applicationData[] = Layer::withName($module.'.ApplicationData')->collectors($data);
            $events[] = Layer::withName($module.'.EventData')->collectors($event);
            $domains[] = Layer::withName($module.'.Domain')->collectors($domain);
            $applications[] = Layer::withName($module.'.Application')->collectors(BoolConfig::create([$application], [$data]));
            $internals[] = Layer::withName($module.'.Internal')->collectors(BoolConfig::create([$all], [$data, $event, $domain, $application]));
            $classified[] = $all;
        }
        // No App dependency falls through to the permissive external dependency layer.
        // SourceRules also rejects unclassified declarations with no graph edges.
        $unclassified = Layer::withName('Unclassified')->collectors(BoolConfig::create(
            [self::collector('~^App(?:\\\\|$)~i')],
            $classified,
        ));
        $publicData = [...$applicationData, ...$events];
        $config
            ->paths($projectDir.'/src')
            ->cacheFile($projectDir.'/var/deptrac.cache')
            ->analyser(AnalyserConfig::create([EmitterType::CLASS_TOKEN, EmitterType::USE_TOKEN]))
            ->layers($values, $mapping, $persistence, $vendor, $platform, $kernel, $unclassified, ...array_merge($publicData, $domains, $applications, $internals))
            ->rulesets(
                Ruleset::forLayer($values),
                Ruleset::forLayer($mapping),
                Ruleset::forLayer($persistence),
                Ruleset::forLayer($vendor),
                Ruleset::forLayer($unclassified),
                Ruleset::forLayer($platform)->accesses($vendor, $values, $mapping, $persistence, ...$publicData),
                Ruleset::forLayer($kernel)->accesses($platform, $vendor, $values),
            );
        foreach ($applicationData as $data) {
            $config->rulesets(Ruleset::forLayer($data)->accesses($values, ...$publicData));
        }
        foreach ($events as $event) {
            $config->rulesets(Ruleset::forLayer($event)->accesses($values, ...$events));
        }
        foreach ($internals as $index => $internal) {
            $domain = $domains[$index];
            $application = $applications[$index];
            $config->rulesets(
                Ruleset::forLayer($domain)->accesses($vendor, $values, $mapping, ...$events),
                Ruleset::forLayer($application)->accesses($domain, $vendor, $values, ...$publicData),
                Ruleset::forLayer($internal)->accesses($domain, $application, $vendor, $values, $mapping, $persistence, ...$publicData),
            );
        }
    }

    private static function layer(string $name, string $pattern): Layer
    {
        return Layer::withName($name)->collectors(self::collector($pattern));
    }

    private static function collector(string $pattern): CollectorConfig
    {
        // Deptrac 4.7's PHP builder escapes namespace separators itself. Keep
        // ordinary PCRE patterns in our vocabulary, escaping exactly once here.
        return ClassNameRegexConfig::create(str_replace('\\\\', '\\', $pattern));
    }
}
