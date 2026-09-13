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
        $messengerPattern = '~^Symfony\\\\Component\\\\Messenger\\\\~i';
        $declarationPattern = '~^Symfony\\\\Component\\\\Messenger\\\\(?:Attribute\\\\AsMessageHandler|Exception\\\\ValidationFailedException)$~Di';
        $facadePattern = '~^App\\\\Platform\\\\Messaging\\\\(?:CommandBus|QueryBus)$~Di';
        $messenger = Layer::withName('MessagingRuntime')->collectors(BoolConfig::create([self::collector($messengerPattern)], [self::collector($declarationPattern)]));
        $facades = self::layer('MessagingFacades', $facadePattern);
        $eventBusPattern = '~^App\\\\Platform\\\\Messaging\\\\EventBus$~Di';
        $eventBus = self::layer('EventBus', $eventBusPattern);
        $primitivePattern = '~^App\\\\Platform\\\\Event\\\\(?:Base|Domain|Application|Infrastructure)Event$~Di';
        $baseEvent = self::layer('BaseEvent', '~^App\\\\Platform\\\\Event\\\\BaseEvent$~Di');
        $domainEvent = self::layer('DomainEvent', '~^App\\\\Platform\\\\Event\\\\DomainEvent$~Di');
        $applicationEvent = self::layer('ApplicationEvent', '~^App\\\\Platform\\\\Event\\\\ApplicationEvent$~Di');
        $infrastructureEvent = self::layer('InfrastructureEvent', '~^App\\\\Platform\\\\Event\\\\InfrastructureEvent$~Di');
        $recordingPattern = '~^App\\\\Platform\\\\Event\\\\Recording\\\\(?:RecordsDomainEvents|RecordsDomainEventsTrait)$~Di';
        $recording = self::layer('DomainEventRecording', $recordingPattern);
        $handlerPattern = '~^Symfony\\\\Component\\\\Messenger\\\\Attribute\\\\AsMessageHandler$~Di';
        $handlerAttribute = self::layer('MessageHandlerAttribute', $handlerPattern);
        $declarations = Layer::withName('MessagingDeclarations')->collectors(BoolConfig::create([self::collector($declarationPattern)], [self::collector($handlerPattern)]));
        $vendor = Layer::withName('Vendor')->collectors(BoolConfig::create(
            [self::collector('~^(?!App(?:\\\\|$)).+~i')],
            [self::collector(ContractTypes::IMMUTABLE_PATTERN), self::collector($persistencePattern), self::collector($messengerPattern)],
        ));
        $platform = Layer::withName('Platform')->collectors(BoolConfig::create([self::collector('~^App\\\\Platform\\\\~i')], [self::collector($facadePattern), self::collector($eventBusPattern), self::collector($primitivePattern), self::collector($recordingPattern)]));
        $kernel = self::layer('KernelBoot', '~^App\\\\Kernel$~Di');
        $classified = [
            self::collector('~^App\\\\Platform\\\\~i'),
            self::collector('~^App\\\\Kernel$~Di'),
        ];
        $applicationData = $events = $internals = $domains = $applications = $uis = [];
        $domainEvents = $infrastructureEvents = $listeners = $frameworkListeners = [];
        foreach ((new ModuleMap($projectDir))->modules() as $module) {
            $prefix = '~^'.preg_quote('App\\Module\\'.$module.'\\', '~');
            $data = self::collector(ContractTypes::applicationPattern($module).'i');
            $event = self::collector(ContractTypes::eventPattern($module).'i');
            $domainData = self::collector(ContractTypes::categoryPattern('Domain', $module).'i');
            $infrastructureData = self::collector(ContractTypes::categoryPattern('Infrastructure', $module).'i');
            $listener = self::collector(ContractTypes::listenerPattern($module).'i');
            $frameworkListener = self::collector(ContractTypes::frameworkListenerPattern($module).'i');
            $domain = self::collector($prefix.'Domain\\\\~i');
            $application = self::collector($prefix.'Application\\\\~i');
            $ui = self::collector($prefix.'UI\\\\~i');
            $all = self::collector($prefix.'~i');
            $applicationData[] = Layer::withName($module.'.ApplicationData')->collectors($data);
            $events[] = Layer::withName($module.'.EventData')->collectors($event);
            $domainEvents[] = Layer::withName($module.'.DomainEventData')->collectors($domainData);
            $infrastructureEvents[] = Layer::withName($module.'.InfrastructureEventData')->collectors($infrastructureData);
            $listeners[] = Layer::withName($module.'.EventListener')->collectors($listener);
            $frameworkListeners[] = Layer::withName($module.'.FrameworkEventListener')->collectors($frameworkListener);
            $domains[] = Layer::withName($module.'.Domain')->collectors(BoolConfig::create([$domain], [$domainData]));
            $applications[] = Layer::withName($module.'.Application')->collectors(BoolConfig::create([$application], [$data, $event]));
            $uis[] = Layer::withName($module.'.UI')->collectors($ui);
            $internals[] = Layer::withName($module.'.Internal')->collectors(BoolConfig::create([$all], [$data, $event, $domain, $application, $ui, $infrastructureData, $listener, $frameworkListener]));
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
            ->layers($values, $mapping, $persistence, $vendor, $platform, $kernel, $unclassified, $messenger, $declarations, $facades, $eventBus, $baseEvent, $domainEvent, $applicationEvent, $infrastructureEvent, $recording, $handlerAttribute, ...array_merge($publicData, $domains, $applications, $internals, $uis, $domainEvents, $infrastructureEvents, $listeners, $frameworkListeners))
            ->rulesets(
                Ruleset::forLayer($values),
                Ruleset::forLayer($mapping),
                Ruleset::forLayer($persistence),
                Ruleset::forLayer($vendor),
                Ruleset::forLayer($messenger),
                Ruleset::forLayer($declarations),
                Ruleset::forLayer($handlerAttribute),
                Ruleset::forLayer($baseEvent),
                Ruleset::forLayer($domainEvent)->accesses($baseEvent),
                Ruleset::forLayer($applicationEvent)->accesses($baseEvent),
                Ruleset::forLayer($infrastructureEvent)->accesses($baseEvent),
                Ruleset::forLayer($recording)->accesses($domainEvent),
                Ruleset::forLayer($eventBus)->accesses($platform, $vendor, $messenger, $applicationEvent),
                Ruleset::forLayer($facades)->accesses($platform, $vendor, $messenger),
                Ruleset::forLayer($unclassified),
                Ruleset::forLayer($platform)->accesses($vendor, $values, $mapping, $persistence, $facades, $messenger, $declarations, $eventBus, $baseEvent, $domainEvent, $applicationEvent, $infrastructureEvent, $handlerAttribute, ...$publicData),
                Ruleset::forLayer($kernel)->accesses($platform, $vendor, $values),
            );
        foreach ($applicationData as $data) {
            $config->rulesets(Ruleset::forLayer($data)->accesses($values, ...$publicData));
        }
        foreach ($events as $event) {
            // Deptrac emits transitive inheritance edges to BaseEvent. SourceRules
            // separately requires the direct category parent and rejects primitive payloads.
            $config->rulesets(Ruleset::forLayer($event)->accesses($values, $baseEvent, $applicationEvent, ...$events));
        }
        foreach ($internals as $index => $internal) {
            $domain = $domains[$index];
            $application = $applications[$index];
            $config->rulesets(
                Ruleset::forLayer($domainEvents[$index])->accesses($values, $baseEvent, $domainEvent),
                Ruleset::forLayer($infrastructureEvents[$index])->accesses($values, $baseEvent, $infrastructureEvent),
                Ruleset::forLayer($domain)->accesses($vendor, $values, $mapping, $domainEvent, $recording, $domainEvents[$index]),
                Ruleset::forLayer($application)->accesses($domain, $domainEvents[$index], $vendor, $values, $facades, $eventBus, $declarations, $handlerAttribute, ...$publicData),
                Ruleset::forLayer($listeners[$index])->accesses($values, $facades, $handlerAttribute, ...$publicData),
                Ruleset::forLayer($frameworkListeners[$index])->accesses($domain, $domainEvents[$index], $application, $internal, $infrastructureEvents[$index], $vendor, $values, $mapping, $persistence, ...$publicData),
                Ruleset::forLayer($internal)->accesses($domain, $domainEvents[$index], $application, $infrastructureEvents[$index], $vendor, $values, $mapping, $persistence, ...$publicData),
                Ruleset::forLayer($uis[$index])->accesses($domain, $domainEvents[$index], $application, $internal, $infrastructureEvents[$index], $vendor, $values, $mapping, $persistence, $facades, $declarations, $handlerAttribute, ...$publicData),
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
