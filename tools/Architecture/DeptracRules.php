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
        $facadePattern = '~^App\\\\Platform\\\\Messaging\\\\CommandBus$~Di';
        $queryBusPattern = '~^App\\\\Platform\\\\Messaging\\\\QueryBus$~Di';
        $queryBus = self::layer('QueryBus', $queryBusPattern);
        $policyExceptions = self::layer('PolicyExceptions', ContractTypes::POLICY_EXCEPTION_PATTERN.'i');
        $contextPattern = '~^App\\\\Platform\\\\Authorization\\\\(?:Actor|ActorKind|AuthorizationToken)$~Di';
        $authorizationAttributePattern = '~^App\\\\Platform\\\\Authorization\\\\Authorize$~Di';
        $deniedPattern = '~^App\\\\Platform\\\\Authorization\\\\AuthorizationDenied$~Di';
        $operatorPattern = '~^App\\\\Platform\\\\Authorization\\\\OperatorExecution$~Di';
        $authenticationPattern = '~^App\\\\Platform\\\\Authorization\\\\AuthenticationExecution$~Di';
        $publicVoterPattern = '~^App\\\\Platform\\\\Authorization\\\\PublicAccessVoter$~Di';
        $voterRuntimePattern = '~^Symfony\\\\Component\\\\Security\\\\Core\\\\(?:Authentication\\\\Token\\\\TokenInterface|Authorization\\\\Voter\\\\(?:Vote|Voter|VoterInterface))$~Di';
        $context = self::layer('AuthorizationFacts', $contextPattern);
        $authorizationAttribute = self::layer('Authorize', $authorizationAttributePattern);
        $denied = self::layer('AuthorizationDenied', $deniedPattern);
        $operator = self::layer('OperatorExecution', $operatorPattern);
        $authentication = self::layer('AuthenticationExecution', $authenticationPattern);
        $publicVoter = self::layer('PublicAccessVoter', $publicVoterPattern);
        $voterRuntime = self::layer('AuthorizationVoterRuntime', $voterRuntimePattern);
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
            [self::collector(ContractTypes::IMMUTABLE_PATTERN), self::collector(ContractTypes::POLICY_EXCEPTION_PATTERN.'i'), self::collector($persistencePattern), self::collector($messengerPattern), self::collector($voterRuntimePattern)],
        ));
        $platform = Layer::withName('Platform')->collectors(BoolConfig::create([self::collector('~^App\\\\Platform\\\\~i')], array_map(self::collector(...), [$facadePattern, $queryBusPattern, $eventBusPattern, $primitivePattern, $recordingPattern, $contextPattern, $authorizationAttributePattern, $deniedPattern, $operatorPattern, $authenticationPattern, $publicVoterPattern])));
        $kernel = self::layer('KernelBoot', '~^App\\\\Kernel$~Di');
        $classified = [
            self::collector('~^App\\\\Platform\\\\~i'),
            self::collector('~^App\\\\Kernel$~Di'),
        ];
        $applicationData = $events = $internals = $domains = $applications = $uis = [];
        $domainEvents = $infrastructureEvents = $listeners = $frameworkListeners = [];
        $voters = $handlers = $operatorAdapters = $authenticationAdapters = [];
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
            $voter = self::collector(ContractTypes::authorizationVoterPattern($module).'i');
            $handler = self::collector(ContractTypes::handlerPattern($module).'i');
            $operatorAdapter = self::collector(self::executionAdapterPattern($module, 'App\\Platform\\Authorization\\OperatorExecution'));
            $authenticationAdapter = self::collector(self::executionAdapterPattern($module, 'App\\Platform\\Authorization\\AuthenticationExecution'));
            $ui = self::collector($prefix.'UI\\\\~i');
            $all = self::collector($prefix.'~i');
            $applicationData[] = Layer::withName($module.'.ApplicationData')->collectors($data);
            $events[] = Layer::withName($module.'.EventData')->collectors($event);
            $domainEvents[] = Layer::withName($module.'.DomainEventData')->collectors($domainData);
            $infrastructureEvents[] = Layer::withName($module.'.InfrastructureEventData')->collectors($infrastructureData);
            $listeners[] = Layer::withName($module.'.EventListener')->collectors($listener);
            $frameworkListeners[] = Layer::withName($module.'.FrameworkEventListener')->collectors($frameworkListener);
            $domains[] = Layer::withName($module.'.Domain')->collectors(BoolConfig::create([$domain], [$domainData]));
            $applications[] = Layer::withName($module.'.Application')->collectors(BoolConfig::create([$application], [$data, $event, $handler]));
            $voters[] = Layer::withName($module.'.AuthorizationVoter')->collectors($voter);
            $handlers[] = Layer::withName($module.'.Handler')->collectors($handler);
            $operatorAdapters[] = Layer::withName($module.'.OperatorAdapter')->collectors($operatorAdapter);
            $authenticationAdapters[] = Layer::withName($module.'.AuthenticationAdapter')->collectors($authenticationAdapter);
            $uis[] = Layer::withName($module.'.UI')->collectors(BoolConfig::create([$ui], [$operatorAdapter, $authenticationAdapter]));
            $internals[] = Layer::withName($module.'.Internal')->collectors(BoolConfig::create([$all], [$data, $event, $domain, $application, $ui, $infrastructureData, $listener, $frameworkListener, $voter]));
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
            ->layers($values, $mapping, $persistence, $vendor, $platform, $kernel, $unclassified, $messenger, $declarations, $facades, $queryBus, $eventBus, $baseEvent, $domainEvent, $applicationEvent, $infrastructureEvent, $recording, $handlerAttribute, $policyExceptions, $context, $authorizationAttribute, $denied, $operator, $authentication, $publicVoter, $voterRuntime, ...array_merge($publicData, $domains, $applications, $internals, $uis, $domainEvents, $infrastructureEvents, $listeners, $frameworkListeners, $voters, $handlers, $operatorAdapters, $authenticationAdapters))
            ->rulesets(
                Ruleset::forLayer($values),
                Ruleset::forLayer($mapping),
                Ruleset::forLayer($persistence),
                Ruleset::forLayer($vendor),
                Ruleset::forLayer($messenger),
                Ruleset::forLayer($declarations),
                Ruleset::forLayer($handlerAttribute),
                Ruleset::forLayer($policyExceptions),
                Ruleset::forLayer($context)->accesses($values, $vendor, $policyExceptions, $voterRuntime),
                Ruleset::forLayer($authorizationAttribute)->accesses($vendor),
                Ruleset::forLayer($denied)->accesses($policyExceptions),
                Ruleset::forLayer($operator)->accesses($platform, $context, $policyExceptions),
                Ruleset::forLayer($authentication)->accesses($platform, $context, $values),
                Ruleset::forLayer($publicVoter)->accesses($context, $voterRuntime),
                Ruleset::forLayer($voterRuntime),
                Ruleset::forLayer($baseEvent),
                Ruleset::forLayer($domainEvent)->accesses($baseEvent),
                Ruleset::forLayer($applicationEvent)->accesses($baseEvent),
                Ruleset::forLayer($infrastructureEvent)->accesses($baseEvent),
                Ruleset::forLayer($recording)->accesses($domainEvent),
                Ruleset::forLayer($eventBus)->accesses($platform, $vendor, $policyExceptions, $messenger, $applicationEvent),
                Ruleset::forLayer($facades)->accesses($platform, $vendor, $policyExceptions, $messenger),
                Ruleset::forLayer($queryBus)->accesses($platform, $vendor, $policyExceptions, $messenger),
                Ruleset::forLayer($unclassified),
                Ruleset::forLayer($platform)->accesses($vendor, $policyExceptions, $values, $mapping, $persistence, $facades, $queryBus, $messenger, $declarations, $eventBus, $baseEvent, $domainEvent, $applicationEvent, $infrastructureEvent, $handlerAttribute, $context, $authorizationAttribute, $denied, $operator, $authentication, $publicVoter, $voterRuntime, ...$publicData),
                Ruleset::forLayer($kernel)->accesses($platform, $vendor, $policyExceptions, $values),
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
                Ruleset::forLayer($domain)->accesses($vendor, $policyExceptions, $values, $mapping, $domainEvent, $recording, $domainEvents[$index]),
                Ruleset::forLayer($application)->accesses($domain, $domainEvents[$index], $vendor, $policyExceptions, $values, $facades, $queryBus, $eventBus, $declarations, $handlerAttribute, ...$publicData),
                Ruleset::forLayer($handlers[$index])->accesses($application, $voters[$index], $authorizationAttribute, $domain, $domainEvents[$index], $vendor, $policyExceptions, $values, $facades, $queryBus, $eventBus, $declarations, $handlerAttribute, ...$publicData),
                Ruleset::forLayer($voters[$index])->accesses($domain, $values, $policyExceptions, $queryBus, $context, $voterRuntime, ...$publicData),
                Ruleset::forLayer($listeners[$index])->accesses($values, $facades, $queryBus, $handlerAttribute, ...$publicData),
                Ruleset::forLayer($frameworkListeners[$index])->accesses($domain, $domainEvents[$index], $application, $internal, $infrastructureEvents[$index], $vendor, $policyExceptions, $values, $mapping, $persistence, ...$publicData),
                Ruleset::forLayer($internal)->accesses($domain, $domainEvents[$index], $application, $infrastructureEvents[$index], $vendor, $policyExceptions, $values, $mapping, $persistence, ...$publicData),
            );
            $uiDependencies = [$domain, $domainEvents[$index], $application, $internal, $infrastructureEvents[$index], $vendor, $policyExceptions, $values, $mapping, $persistence, $facades, $queryBus, $declarations, $handlerAttribute, $denied, ...$publicData];
            $config->rulesets(
                Ruleset::forLayer($uis[$index])->accesses($operatorAdapters[$index], $authenticationAdapters[$index], ...$uiDependencies),
                Ruleset::forLayer($operatorAdapters[$index])->accesses($uis[$index], $operator, ...$uiDependencies),
                Ruleset::forLayer($authenticationAdapters[$index])->accesses($uis[$index], $authentication, ...$uiDependencies),
            );
        }
    }

    private static function layer(string $name, string $pattern): Layer
    {
        return Layer::withName($name)->collectors(self::collector($pattern));
    }

    private static function executionAdapterPattern(string $module, string $facade): string
    {
        $classes = array_filter(ContractTypes::executionFacadeConsumers()[$facade] ?? [], static fn (string $class): bool => ModuleMap::owner($class) === $module);

        return [] === $classes ? '~(?!)~' : '~^(?:'.implode('|', array_map(static fn (string $class): string => preg_quote($class, '~'), $classes)).')$~Di';
    }

    private static function collector(string $pattern): CollectorConfig
    {
        // Deptrac 4.7's PHP builder escapes namespace separators itself. Keep
        // ordinary PCRE patterns in our vocabulary, escaping exactly once here.
        return ClassNameRegexConfig::create(str_replace('\\\\', '\\', $pattern));
    }
}
