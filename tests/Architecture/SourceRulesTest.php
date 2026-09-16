<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tools\Architecture\SourceRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SourceRulesTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir().'/source-architecture-'.bin2hex(random_bytes(12));
        $this->writeClass('App\\Module\\TaskTracking\\Domain\\Task', 'final class Task {}');
        $this->writeClass('App\\Module\\Authorizing\\Domain\\Grant', 'final class Grant {}');
        $this->writeClass('App\\Module\\Authorizing\\Application\\GetGrant\\GetGrantResult', 'final readonly class GetGrantResult { public function __construct(public string $id) {} }');
        $this->writeClass('App\\Platform\\Event\\BaseEvent', 'abstract readonly class BaseEvent {}');
        foreach (['Domain', 'Application', 'Infrastructure'] as $category) {
            $this->writeClass('App\\Platform\\Event\\'.$category.'Event', 'abstract readonly class '.$category.'Event extends BaseEvent {}');
        }
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testDataContractsAndModuleResourcesAreAcceptedWithoutExecutingSource(): void
    {
        $this->writeClass('App\\Module\\TaskTracking\\Application\\Lookup\\StatusResult', "enum StatusResult: string { case Open = 'open'; case Closed = 'closed'; }");
        $this->writeClass('App\\Module\\TaskTracking\\Application\\Lookup\\RankResult', 'enum RankResult: int { case Low = -1; case High = 2; }');
        $this->writeClass('App\\Module\\TaskTracking\\Application\\Empty\\EmptyCommand', 'final readonly class EmptyCommand {}');
        $this->writeClass('App\\Module\\TaskTracking\\Application\\Lookup\\LookupQuery', <<<'PHP'
            use App\Module\Authorizing\Application\GetGrant\{GetGrantResult as ForeignView};
            use DateTimeImmutable as Instant;
            use Symfony\Component\Uid\Uuid;

            final readonly class LookupQuery
            {
                public function __construct(
                    public ForeignView $grant,
                    public Instant $at,
                    public Uuid $id,
                    public string|int $key,
                    public ?string $label = null,
                    public readonly bool $enabled = true,
                    public float $ratio = -1.5,
                ) {}
            }
            PHP);
        $this->writeClass('App\\Module\\TaskTracking\\Application\\Lookup\\LookupHandler', 'final class LookupHandler { public function __invoke(LookupQuery $query): \App\Module\Authorizing\Application\GetGrant\GetGrantResult { return $query->grant; } }');
        $this->writeClass('App\\Module\\TaskTracking\\Resources\\migrations\\Version20260911000100', 'final class Version20260911000100 {}');
        $this->write('src/Module/TaskTracking/Resources/config/services.yaml', "services: {}\n");
        $this->writeClass('App\\Platform\\Http\\Controller', 'final class Controller {}');
        $this->writeClass('App\\Kernel', 'class Kernel {}');

        self::assertSame([], (new SourceRules())->violations($this->root));
    }

    public function testClasslessPhpConfigurationCannotHideAForeignInternalDependency(): void
    {
        $this->write('src/Module/TaskTracking/Resources/config/services.php', <<<'PHP'
            <?php
            use App\Module\Authorizing\Domain\Grant;
            new Grant();
            return static function (): void {};
            PHP);
        $this->assertSourceDiagnostic('source.config', 'src/Module/TaskTracking/Resources/config/services.php');
    }

    public function testExactMessagingMigrationLayoutIsAccepted(): void
    {
        $this->writeClass('App\\Platform\\Messaging\\Resources\\migrations\\Version20260912000200', 'final class Version20260912000200 {}');

        self::assertSame([], (new SourceRules())->violations($this->root));
    }

    public function testMessagingMigrationExceptionDoesNotPermitOtherResourceNamespaces(): void
    {
        foreach (['App\\Platform\\Other\\Resources\\migrations\\Version20260912000200', 'App\\Platform\\Messaging\\Resources\\migrations\\Helper'] as $class) {
            $name = substr($class, (int) strrpos($class, '\\') + 1);
            $this->writeClass($class, 'final class '.$name.' {}');
            $this->assertSourceDiagnostic('source.layout', $class);
        }
    }

    #[DataProvider('invalidContracts')]
    public function testContractShapeAndDataTypesAreEnforced(string $declaration, string $rule): void
    {
        $class = 'App\\Module\\TaskTracking\\Application\\Example\\ExampleResult';
        $this->writeClass($class, str_replace('Example', 'ExampleResult', $declaration));

        $this->assertSourceDiagnostic($rule, $class);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidContracts(): iterable
    {
        yield 'mutable DTO' => ['final class Example {}', 'contract.shape'];
        yield 'extensible DTO' => ['readonly class Example {}', 'contract.shape'];
        yield 'interface facade' => ['interface Example { public function execute(): void; }', 'contract.shape'];
        yield 'trait facade' => ['trait Example { public function execute(): void {} }', 'contract.shape'];
        yield 'inheritance' => ['final readonly class Example extends \App\Module\TaskTracking\Domain\Task {}', 'contract.shape'];
        yield 'implemented behavior' => ['final readonly class Example implements \Stringable { public function __toString(): string { return ""; } }', 'contract.shape'];
        yield 'callable facade' => ['final readonly class Example { public function __invoke(): void {} }', 'contract.behavior'];
        yield 'getter behavior' => ['final readonly class Example { public function value(): string { return "value"; } }', 'contract.behavior'];
        yield 'trait behavior' => ['final readonly class Example { use \App\Module\TaskTracking\Domain\Behavior; }', 'contract.behavior'];
        yield 'constant dependency' => ['final readonly class Example { public const VALUE = \App\Module\TaskTracking\Domain\Task::class; }', 'contract.behavior'];
        yield 'nonpromoted property' => ['final readonly class Example { public string $value; }', 'contract.behavior'];
        yield 'constructor facade' => ['final readonly class Example { public function __construct(public string $id) { file_put_contents("/tmp/forbidden", $id); } }', 'contract.constructor'];
        yield 'private constructor' => ['final readonly class Example { private function __construct() {} }', 'contract.constructor'];
        yield 'unpromoted parameter' => ['final readonly class Example { public function __construct(string $id) {} }', 'contract.property'];
        yield 'private promoted parameter' => ['final readonly class Example { public function __construct(private string $id) {} }', 'contract.property'];
        yield 'reference parameter' => ['final readonly class Example { public function __construct(public string &$id) {} }', 'contract.property'];
        yield 'variadic parameter' => ['final readonly class Example { public function __construct(string ...$ids) {} }', 'contract.property'];
        yield 'property hook facade' => ['final class Example { public function __construct(public string $value { get => "behavior"; }) {} }', 'contract.property'];
        yield 'class attribute' => ['#[\Deprecated] final readonly class Example {}', 'contract.attributes'];
        yield 'parameter attribute' => ['final readonly class Example { public function __construct(#[\SensitiveParameter] public string $id) {} }', 'contract.attributes'];
        yield 'constructor attribute' => ['final readonly class Example { #[\Deprecated] public function __construct() {} }', 'contract.attributes'];
        yield 'untyped parameter' => ['final readonly class Example { public function __construct($value) {} }', 'contract.type'];
        foreach (['mixed', 'object', 'array', 'iterable', '\Closure', '\DateTime', '\DateTimeInterface', '\Symfony\Component\Uid\AbstractUid', '\App\Module\TaskTracking\Domain\Task', '\App\Module\Authorizing\Domain\Grant', '?\App\Module\Authorizing\Domain\Grant', '\App\Platform\Entity', '\App\Module\Authorizing\Application\Missing\MissingResult', 'string|\App\Module\Authorizing\Domain\Grant', 'string|array'] as $type) {
            yield 'forbidden type '.$type => ['final readonly class Example { public function __construct(public '.$type.' $value) {} }', 'contract.type'];
        }
        yield 'callable parameter' => ['final readonly class Example { public function __construct(callable $value) {} }', 'contract.type'];
        yield 'intersection type' => ['final readonly class Example { public function __construct(public \Countable&\Iterator $value) {} }', 'contract.type'];
        yield 'construction default' => ['final readonly class Example { public function __construct(public \DateTimeImmutable $value = new \DateTimeImmutable()) {} }', 'contract.default'];
        yield 'constant default' => ['final readonly class Example { public function __construct(public string $value = \App\Module\TaskTracking\Domain\Task::class) {} }', 'contract.default'];
        yield 'unit enum' => ['enum Example { case Open; }', 'contract.enum'];
        yield 'enum behavior' => ['enum Example: string { case Open = "open"; public function handle(): void {} }', 'contract.enum'];
        yield 'enum interface' => ['enum Example: string implements \JsonSerializable { case Open = "open"; public function jsonSerialize(): mixed { return $this->value; } }', 'contract.enum'];
        yield 'enum trait' => ['enum Example: string { use \App\Module\TaskTracking\Domain\Behavior; case Open = "open"; }', 'contract.enum'];
        yield 'enum constant dependency' => ['enum Example: string { case Open = \App\Module\TaskTracking\Domain\Task::class; }', 'contract.enum'];
        yield 'enum wrong value type' => ['enum Example: int { case Open = "open"; }', 'contract.enum'];
        yield 'enum duplicate value' => ['enum Example: int { case Open = 1; case Closed = 1; }', 'contract.enum'];
        yield 'enum attribute' => ['enum Example: string { #[\Deprecated] case Open = "open"; }', 'contract.attributes'];
    }

    public function testEveryPublicDataRoleRejectsBehaviorOrMutability(): void
    {
        foreach (['Command', 'Query', 'Input'] as $kind) {
            $class = 'App\\Module\\TaskTracking\\Application\\Example\\Example'.$kind;
            $this->writeClass($class, 'final class Example'.$kind.' {}');
            $this->assertSourceDiagnostic('contract.shape', $class);
        }
        $event = 'App\\Module\\TaskTracking\\Application\\Example\\HappenedEvent';
        $this->writeClass($event, 'final readonly class HappenedEvent extends \\App\\Platform\\Event\\ApplicationEvent { public function handle(): void {} }');
        $this->assertSourceDiagnostic('contract.behavior', $event);
    }

    public function testEventPayloadCannotIntroduceAnIndirectApplicationDependency(): void
    {
        $event = 'App\\Module\\TaskTracking\\Application\\Example\\HappenedEvent';
        $this->writeClass($event, 'final readonly class HappenedEvent extends \\App\\Platform\\Event\\ApplicationEvent { public function __construct(public string|\App\Module\Authorizing\Application\GetGrant\GetGrantResult|null $payload) {} }');
        $this->assertSourceDiagnostic('contract.type', $event);
    }

    public function testAllEventCategoriesAndDistinctListenerLayersHaveInwardDependencies(): void
    {
        foreach (['Domain/Event' => 'Domain', 'Application/Observe' => 'Application', 'Infrastructure/Event' => 'Infrastructure'] as $path => $category) {
            $this->writeClass('App\\Module\\TaskTracking\\'.str_replace('/', '\\', $path).'\\ObservedEvent', 'use App\\Platform\\Event\\'.$category.'Event as Category; final readonly class ObservedEvent extends Category { public function __construct(public string $id, public \\DateTimeImmutable $at) {} }');
        }
        $this->writeClass('App\\Module\\TaskTracking\\Domain\\Observer', 'final class Observer { public function observe(\\App\\Platform\\Event\\DomainEvent $event): void {} }');
        $this->writeClass('App\\Module\\TaskTracking\\Infrastructure\\EventListener\\ObservedListener', 'use Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler; #[AsMessageHandler(bus: "event.bus")] final class ObservedListener { public function __construct(private \\App\\Platform\\Messaging\\CommandBus $commands) {} public function __invoke(\\App\\Module\\TaskTracking\\Application\\Observe\\ObservedEvent $event): void {} }');
        $this->writeClass('App\\Module\\TaskTracking\\Application\\Observe\\ObserveHandler', 'final class ObserveHandler { public function __construct(private \\App\\Platform\\Messaging\\EventBus $events) {} }');
        $this->writeClass('App\\Module\\TaskTracking\\Infrastructure\\Framework\\Doctrine\\EventListener\\FlushListener', 'final class FlushListener { public function __construct(private \\Doctrine\\ORM\\EntityManagerInterface $manager) {} }');
        self::assertSame([], (new SourceRules())->violations($this->root));
        $process = $this->deptrac();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        /** @var array{Report: array<string, int>} $report */
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        foreach (['Violations', 'Uncovered', 'Errors', 'Warnings'] as $counter) {
            self::assertSame(0, $report['Report'][$counter], $process->getOutput());
        }
    }

    public function testSameModuleDirectListenerInvocationIsRejectedEvenWhenDeptracAllowsIt(): void
    {
        $this->writeListenerPair('(new LastTaskListener($this->commands))($event);');
        $process = $this->deptrac();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        /** @var array{Report: array<string, int>} $report */
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $report['Report']['Violations']);
        $violations = (new SourceRules())->violations($this->root);
        self::assertCount(1, $violations);
        self::assertStringStartsWith('event.listener_dependency: src/Module/TaskTracking/Infrastructure/EventListener/FirstTaskListener.php:', $violations[0]);
        self::assertStringContainsString('App\\Module\\TaskTracking\\Infrastructure\\EventListener\\FirstTaskListener: must not depend on listener App\\Module\\TaskTracking\\Infrastructure\\EventListener\\LastTaskListener;', $violations[0]);
    }

    public function testOptInDomainRecordingIsReusableWithoutMakingEntitiesPublic(): void
    {
        $this->writeClass('App\\Platform\\Event\\Recording\\RecordsDomainEvents', 'interface RecordsDomainEvents { /** @return list<\\App\\Platform\\Event\\DomainEvent> */ public function releaseEvents(): array; }');
        $this->writeClass('App\\Platform\\Event\\Recording\\RecordsDomainEventsTrait', <<<'PHP'
            use App\Platform\Event\DomainEvent;
            trait RecordsDomainEventsTrait {
                /** @var list<DomainEvent> */ private array $events = [];
                protected function recordEvent(DomainEvent $event): void { $this->events[] = $event; }
                /** @return list<DomainEvent> */
                public function releaseEvents(): array { $events = $this->events; $this->events = []; return $events; }
            }
            PHP);
        foreach (['TaskTracking' => 'Task', 'Authorizing' => 'Grant'] as $module => $entity) {
            $this->writeClass('App\\Module\\'.$module.'\\Domain\\LocalBehavior', 'trait LocalBehavior { private function label(): string { return "local"; } }');
            $this->writeClass('App\\Module\\'.$module.'\\Domain\\'.$entity, str_replace('__ENTITY__', $entity, <<<'PHP'
                use App\Platform\Event\Recording\{RecordsDomainEvents, RecordsDomainEventsTrait};
                use Doctrine\ORM\Mapping as ORM;
                #[ORM\Entity]
                final class __ENTITY__ implements RecordsDomainEvents {
                    use RecordsDomainEventsTrait;
                    use LocalBehavior;
                    #[ORM\Id] #[ORM\Column] private int $id;
                }
                PHP));
            $this->writeClass('App\\Module\\'.$module.'\\Application\\Observe\\ObserveHandler', 'final class ObserveHandler { public function observe(\\App\\Module\\'.$module.'\\Domain\\'.$entity.' $entity): void { foreach ($entity->releaseEvents() as $event) {} } }');
        }
        $this->writeClass('App\\Module\\TaskTracking\\Domain\\Unrecorded', 'final class Unrecorded {}');
        self::assertSame([], (new SourceRules())->violations($this->root));
        $process = $this->deptrac();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        /** @var array{Report: array<string, int>} $report */
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        foreach (['Violations', 'Uncovered', 'Errors', 'Warnings', 'Skipped violations'] as $counter) {
            self::assertSame(0, $report['Report'][$counter], $process->getOutput());
        }
        self::assertGreaterThan(0, $report['Report']['Allowed']);
        $this->writeClass('App\\Module\\TaskTracking\\Application\\ForeignReader', 'final class ForeignReader { public function read(\\App\\Module\\Authorizing\\Domain\\Grant $grant): void { $grant->releaseEvents(); } }');
        $process = $this->deptrac();
        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('TaskTracking.Application on Authorizing.Domain', $process->getOutput());
    }

    public function testIndependentPrioritizedListenersRemainValid(): void
    {
        $this->writeListenerPair();
        self::assertSame([], (new SourceRules())->violations($this->root));
        $process = $this->deptrac();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    /** @return iterable<string, array{string}> */
    public static function listenerClassDependencies(): iterable
    {
        $target = '\\App\\Module\\TaskTracking\\Infrastructure\\EventListener\\LastTaskListener';
        yield 'FQCN construction' => ['final class FirstTaskListener { public function call(): void { new '.$target.'(); } }'];
        yield 'case insensitive construction' => ['final class FirstTaskListener { public function call(): void { new '.strtolower($target).'(); } }'];
        yield 'aliased construction' => ['use '.$target.' as Other; final class FirstTaskListener { public function call(): void { new Other(); } }'];
        yield 'group import alias' => ['use App\\Module\\TaskTracking\\Infrastructure\\EventListener\\{LastTaskListener as Other}; final class FirstTaskListener { public function call(): void { new Other(); } }'];
        yield 'namespace alias' => ['use App\\Module\\TaskTracking\\Infrastructure\\EventListener as Listeners; final class FirstTaskListener { public function call(): void { new Listeners\\LastTaskListener(); } }'];
        yield 'unused class import' => ['use '.$target.' as Other; final class FirstTaskListener {}'];
        yield 'unused group class import' => ['use App\\Module\\TaskTracking\\Infrastructure\\EventListener\\{LastTaskListener as Other}; final class FirstTaskListener {}'];
        yield 'static call' => ['final class FirstTaskListener { public function call(): void { LastTaskListener::handle(); } }'];
        yield 'static property' => ['final class FirstTaskListener { public function call(): void { LastTaskListener::$handler; } }'];
        yield 'class constant' => ['final class FirstTaskListener { public const TARGET = LastTaskListener::class; }'];
        yield 'inheritance' => ['class FirstTaskListener extends LastTaskListener {}'];
        yield 'instanceof' => ['final class FirstTaskListener { public function accepts(object $value): bool { return $value instanceof LastTaskListener; } }'];
        yield 'promoted union type' => ['final class FirstTaskListener { public function __construct(public LastTaskListener|string $value) {} }'];
        yield 'property type' => ['final class FirstTaskListener { public ?LastTaskListener $value = null; }'];
        yield 'return type' => ['final class FirstTaskListener { public function value(): ?LastTaskListener { return null; } }'];
        yield 'closure return type' => ['final class FirstTaskListener { public function call(): void { $factory = static fn (): ?LastTaskListener => null; } }'];
        yield 'attribute' => ['#[LastTaskListener] final class FirstTaskListener {}'];
        yield 'parameter attribute alias' => ['use '.$target.' as Other; final class FirstTaskListener { public function call(#[Other] string $value): void {} }'];
        yield 'catch type' => ['final class FirstTaskListener { public function call(): void { try {} catch (LastTaskListener $error) {} } }'];
        yield 'trait use' => ['final class FirstTaskListener { use LastTaskListener; }'];
    }

    #[DataProvider('listenerClassDependencies')]
    public function testResolvedListenerClassDependenciesCannotBypassSourceChecks(string $declaration): void
    {
        $this->writeListenerPair();
        $source = 'App\\Module\\TaskTracking\\Infrastructure\\EventListener\\FirstTaskListener';
        $this->writeClass($source, $declaration);
        $this->assertSourceDiagnostic('event.listener_dependency', $source);
    }

    public function testListenerDependencyCheckDoesNotInterpretStringsOrFunctionNamesAsClasses(): void
    {
        $this->writeListenerPair(<<<'PHP'
            $label = 'App\\Module\\TaskTracking\\Infrastructure\\EventListener\\LastTaskListener';
            $self = FirstTaskListener::class;
            \App\Module\TaskTracking\Infrastructure\EventListener\LastTaskListener();
            PHP);
        self::assertSame([], (new SourceRules())->violations($this->root));
        $this->writeClass('App\\Module\\TaskTracking\\Infrastructure\\EventListener\\FirstTaskListener', <<<'PHP'
            use function App\Module\TaskTracking\Infrastructure\EventListener\LastTaskListener;
            use App\Module\TaskTracking\Infrastructure\EventListener\{function LastTaskListener as action, const LastTaskListener as LABEL};
            final class FirstTaskListener { public function call(): void { LastTaskListener(); action(); $label = LABEL; } }
            PHP);
        self::assertSame([], (new SourceRules())->violations($this->root));
    }

    private function writeListenerPair(string $firstBody = ''): void
    {
        $this->writeClass('App\\Module\\TaskTracking\\Application\\CreateTask\\TaskCreatedEvent', 'final readonly class TaskCreatedEvent extends \\App\\Platform\\Event\\ApplicationEvent { public function __construct(public \\Symfony\\Component\\Uid\\Uuid $taskId) {} }');
        foreach (['First' => 100, 'Last' => -100] as $name => $priority) {
            $this->writeClass('App\\Module\\TaskTracking\\Infrastructure\\EventListener\\'.$name.'TaskListener', str_replace(
                ['__NAME__', '__PRIORITY__', '__BODY__'],
                [$name, (string) $priority, 'First' === $name ? $firstBody : ''],
                <<<'PHP'
                    use App\Module\TaskTracking\Application\CreateTask\TaskCreatedEvent;
                    use App\Platform\Messaging\CommandBus;
                    use Symfony\Component\Messenger\Attribute\AsMessageHandler;

                    #[AsMessageHandler(bus: 'event.bus', priority: __PRIORITY__)]
                    final class __NAME__TaskListener
                    {
                        public function __construct(private readonly CommandBus $commands) {}
                        public function __invoke(TaskCreatedEvent $event): void { __BODY__ }
                    }
                    PHP,
            ));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidEventShapes(): iterable
    {
        yield 'missing parent' => ['final readonly class ExampleEvent {}', 'contract.shape'];
        yield 'wrong category' => ['final readonly class ExampleEvent extends \\App\\Platform\\Event\\DomainEvent {}', 'contract.shape'];
        yield 'base primitive' => ['final readonly class ExampleEvent extends \\App\\Platform\\Event\\BaseEvent {}', 'contract.shape'];
        yield 'intermediate base' => ['final readonly class ExampleEvent extends \\App\\Module\\TaskTracking\\Application\\Intermediate {}', 'contract.shape'];
        yield 'enum event' => ['enum ExampleEvent: string { case Open = "open"; }', 'contract.shape'];
        yield 'abstract event' => ['abstract readonly class ExampleEvent extends \\App\\Platform\\Event\\ApplicationEvent {}', 'contract.shape'];
        yield 'behavior' => ['final readonly class ExampleEvent extends \\App\\Platform\\Event\\ApplicationEvent { public function handle(): void {} }', 'contract.behavior'];
        yield 'recording interface is not event data' => ['final readonly class ExampleEvent extends \\App\\Platform\\Event\\ApplicationEvent implements \\App\\Platform\\Event\\Recording\\RecordsDomainEvents {}', 'contract.shape'];
        yield 'recording trait remains behavior' => ['final readonly class ExampleEvent extends \\App\\Platform\\Event\\ApplicationEvent { use \\App\\Platform\\Event\\Recording\\RecordsDomainEventsTrait; }', 'contract.behavior'];
        yield 'recording support is not public payload' => ['final readonly class ExampleEvent extends \\App\\Platform\\Event\\ApplicationEvent { public function __construct(public \\App\\Platform\\Event\\Recording\\RecordsDomainEvents $recording) {} }', 'contract.type'];
        foreach (['BaseEvent', 'DomainEvent', 'ApplicationEvent', 'InfrastructureEvent'] as $primitive) {
            yield 'abstract payload '.$primitive => ['final readonly class ExampleEvent extends \\App\\Platform\\Event\\ApplicationEvent { public function __construct(public \\App\\Platform\\Event\\'.$primitive.' $event) {} }', 'contract.type'];
        }
        foreach (['Domain\\Event\\ChangedEvent', 'Infrastructure\\Event\\ReceivedEvent', 'Application\\GetTask\\GetTaskResult', 'Domain\\Task'] as $type) {
            yield 'private or CQRS payload '.$type => ['final readonly class ExampleEvent extends \\App\\Platform\\Event\\ApplicationEvent { public function __construct(public \\App\\Module\\TaskTracking\\'.$type.' $payload) {} }', 'contract.type'];
        }
    }

    #[DataProvider('invalidEventShapes')]
    public function testPublicEventsHaveOnlyExactCategoryInheritanceAndPublicPayload(string $declaration, string $rule): void
    {
        $class = 'App\\Module\\TaskTracking\\Application\\Example\\ExampleEvent';
        $this->writeClass($class, $declaration);
        $this->assertSourceDiagnostic($rule, $class);
    }

    public function testInternalEventsCannotCarryDomainEntitiesOrApplicationData(): void
    {
        foreach (['Domain', 'Infrastructure'] as $category) {
            foreach (['\\App\\Module\\TaskTracking\\Domain\\Task', '\\App\\Module\\Authorizing\\Application\\GetGrant\\GetGrantResult', '\\App\\Platform\\Event\\'.$category.'Event'] as $payload) {
                $class = 'App\\Module\\TaskTracking\\'.$category.'\\Event\\SnapshotEvent';
                $this->writeClass($class, 'final readonly class SnapshotEvent extends \\App\\Platform\\Event\\'.$category.'Event { public function __construct(public '.$payload.' $payload) {} }');
                $this->assertSourceDiagnostic('contract.type', $class);
            }
        }
    }

    public function testMisplacedAndIndirectSubclassesCannotHideBehindNonEventNames(): void
    {
        $base = 'App\\Module\\TaskTracking\\Domain\\Intermediate';
        $child = 'App\\Module\\TaskTracking\\Infrastructure\\Misplaced';
        $this->writeClass($base, 'abstract readonly class Intermediate extends \\App\\Platform\\Event\\DomainEvent {}');
        $this->writeClass($child, 'final readonly class Misplaced extends \\'.$base.' {}');
        $this->assertSourceDiagnostic('contract.path', $base);
        $this->assertSourceDiagnostic('contract.path', $child);
    }

    public function testEveryPrimitiveIsCheckedOutsideModuleSource(): void
    {
        foreach (['Base', 'Domain', 'Application', 'Infrastructure'] as $category) {
            $class = 'App\\Platform\\Event\\'.$category.'Event';
            $parent = 'Base' === $category ? '' : ' extends BaseEvent';
            foreach ([
                'abstract readonly class '.$category.'Event'.$parent.' { public const BEHAVIOR = true; }',
                'abstract readonly class '.$category.'Event'.$parent.' { private array $events; }',
                'abstract readonly class '.$category.'Event'.$parent.' { public function __construct(public string $id) {} }',
                'abstract readonly class '.$category.'Event'.$parent.' { public function releaseEvents(): array { return []; } }',
                'abstract readonly class '.$category.'Event'.$parent.' { use \\App\\Platform\\Event\\Recording\\RecordsDomainEventsTrait; }',
                'abstract readonly class '.$category.'Event'.$parent.' implements \\App\\Platform\\Event\\Recording\\RecordsDomainEvents {}',
                'readonly class '.$category.'Event'.$parent.' {}',
                'abstract class '.$category.'Event'.$parent.' {}',
                'abstract readonly class '.$category.'Event extends \\DateTimeImmutable {}',
            ] as $declaration) {
                $this->writeClass($class, $declaration);
                $this->assertSourceDiagnostic('event.primitive', $class);
            }
        }
    }

    public function testMissingPrimitiveDoesNotLeaveAnUncheckedExternalParent(): void
    {
        $this->filesystem->remove($this->root.'/src/Platform/Event/InfrastructureEvent.php');
        $this->assertSourceDiagnostic('event.primitive', 'App\\Platform\\Event\\InfrastructureEvent');
    }

    #[DataProvider('invalidLayouts')]
    public function testNamespaceAndPathCoverageCannotBeBypassed(string $path, string $class, string $rule): void
    {
        $split = strrpos($class, '\\');
        self::assertNotFalse($split);
        $this->write($path, '<?php namespace '.substr($class, 0, $split).'; final readonly class '.substr($class, $split + 1).' {}');
        $this->assertSourceDiagnostic($rule, $class);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidLayouts(): iterable
    {
        yield 'unowned first-party class with no dependencies' => ['src/Service/Example.php', 'App\\Service\\Example', 'source.layout'];
        yield 'foreign namespace under src' => ['src/Module/TaskTracking/Domain/Example.php', 'Foreign\\Example', 'source.layout'];
        yield 'filename mismatch' => ['src/Module/TaskTracking/Domain/Wrong.php', 'App\\Module\\TaskTracking\\Domain\\Example', 'source.path'];
        yield 'namespace case mismatch' => ['src/Module/TaskTracking/domain/Example.php', 'App\\Module\\TaskTracking\\Domain\\Example', 'source.path'];
        yield 'unapproved module directory' => ['src/Module/TaskTracking/Service/Example.php', 'App\\Module\\TaskTracking\\Service\\Example', 'source.layout'];
        yield 'contract outside public kinds' => ['src/Module/TaskTracking/Contract/Example.php', 'App\\Module\\TaskTracking\\Contract\\Example', 'contract.path'];
        yield 'nested event facade' => ['src/Module/TaskTracking/Contract/Event/Service/Example.php', 'App\\Module\\TaskTracking\\Contract\\Event\\Service\\Example', 'contract.path'];
        yield 'internal namespace hidden in contract path' => ['src/Module/TaskTracking/Contract/Event/Example.php', 'App\\Module\\TaskTracking\\Domain\\Example', 'contract.path'];
        foreach (['Command', 'Query', 'Result', 'Input'] as $kind) {
            yield 'obsolete '.$kind.' location' => ['src/Module/TaskTracking/Contract/'.$kind.'/Example.php', 'App\\Module\\TaskTracking\\Contract\\'.$kind.'\\Example', 'contract.path'];
            yield 'missing use case for '.$kind => ['src/Module/TaskTracking/Application/Example'.$kind.'.php', 'App\\Module\\TaskTracking\\Application\\Example'.$kind, 'contract.path'];
            yield 'nested '.$kind.' facade' => ['src/Module/TaskTracking/Application/Example/Service/Example'.$kind.'.php', 'App\\Module\\TaskTracking\\Application\\Example\\Service\\Example'.$kind, 'contract.path'];
        }
        yield 'missing descriptive prefix' => ['src/Module/TaskTracking/Application/Example/Result.php', 'App\\Module\\TaskTracking\\Application\\Example\\Result', 'contract.path'];
        yield 'internal namespace hidden in application data path' => ['src/Module/TaskTracking/Application/Example/ExampleResult.php', 'App\\Module\\TaskTracking\\Domain\\Example', 'contract.path'];
        yield 'old public event layout' => ['src/Module/TaskTracking/Contract/Event/Created.php', 'App\\Module\\TaskTracking\\Contract\\Event\\Created', 'contract.path'];
        yield 'nested Domain event' => ['src/Module/TaskTracking/Domain/Event/Nested/CreatedEvent.php', 'App\\Module\\TaskTracking\\Domain\\Event\\Nested\\CreatedEvent', 'contract.path'];
        yield 'missing application use case' => ['src/Module/TaskTracking/Application/CreatedEvent.php', 'App\\Module\\TaskTracking\\Application\\CreatedEvent', 'contract.path'];
        yield 'nested listener' => ['src/Module/TaskTracking/Infrastructure/EventListener/Nested/CreatedListener.php', 'App\\Module\\TaskTracking\\Infrastructure\\EventListener\\Nested\\CreatedListener', 'event.listener_path'];
        yield 'old UI event listener' => ['src/Module/TaskTracking/UI/Event/CreatedListener.php', 'App\\Module\\TaskTracking\\UI\\Event\\CreatedListener', 'source.layout'];
    }

    public function testInvalidModuleNameAndEmptySourceAreNotVacuousSuccesses(): void
    {
        $this->writeClass('App\\Module\\Tasks\\Domain\\Example', 'final class Example {}');
        $this->assertSourceDiagnostic('module.name', 'Tasks');
        $this->filesystem->remove($this->root.'/src');
        $this->filesystem->mkdir($this->root.'/src');
        $this->assertSourceDiagnostic('source.coverage', 'no named first-party declarations');
    }

    public function testNamedFunctionsAndAnonymousClassesCannotEscapeTheGraph(): void
    {
        $this->write('src/Module/TaskTracking/Domain/Example.php', '<?php namespace App\Module\TaskTracking\Domain; function escape(): object { return new class {}; }');
        $this->assertSourceDiagnostic('source.declaration', 'App\\Module\\TaskTracking\\Domain\\escape');
        $this->assertSourceDiagnostic('source.declaration', '(anonymous)');
    }

    public function testAllowedForeignContractAndNewModuleUseTheRealDeptracGraph(): void
    {
        $this->writeClass('App\\Module\\Authorizing\\Application\\GetGrant\\GetGrantQuery', 'final readonly class GetGrantQuery { public function __construct(public string $id) {} }');
        $this->writeClass('App\\Module\\Authorizing\\Application\\GrantAccess\\GrantAccessCommand', 'final readonly class GrantAccessCommand { public function __construct(public string $id) {} }');
        $this->writeClass('App\\Module\\TaskTracking\\Application\\Reader', 'final class Reader { public function read(\App\Module\Authorizing\Application\GetGrant\GetGrantQuery $query): \App\Module\Authorizing\Application\GetGrant\GetGrantResult { return new \App\Module\Authorizing\Application\GetGrant\GetGrantResult($query->id); } }');
        $this->writeClass('App\\Module\\Auditing\\Application\\Recorder', 'final class Recorder { public function record(\App\Module\Authorizing\Application\GrantAccess\GrantAccessCommand $command): string { return $command->id; } }');
        $this->writeClass('App\\Module\\Auditing\\Application\\Record\\RecordedEvent', 'final readonly class RecordedEvent extends \\App\\Platform\\Event\\ApplicationEvent { public function __construct(public \DateTimeImmutable $at, public \Symfony\Component\Uid\Uuid $id) {} }');
        $this->writeClass('App\\Module\\TaskTracking\\Application\\Link\\LinkedEvent', 'final readonly class LinkedEvent extends \\App\\Platform\\Event\\ApplicationEvent { public function __construct(public \App\Module\Auditing\Application\Record\RecordedEvent $event) {} }');
        $this->writeClass('App\\Module\\TaskTracking\\Application\\EventConsumer', 'final class EventConsumer { public function consume(\App\Module\TaskTracking\Application\Link\LinkedEvent $event): void {} }');
        $this->writeClass('App\\Platform\\Technical', 'final class Technical { public function __construct(private \Symfony\Component\DependencyInjection\ContainerBuilder $container, private \App\Module\Authorizing\Application\GetGrant\GetGrantResult $view) {} }');
        $this->writeClass('App\\Kernel', 'final class Kernel extends \Symfony\Component\HttpKernel\Kernel { public function __construct(private \App\Platform\Technical $technical) {} }');
        self::assertSame([], (new SourceRules())->violations($this->root));

        $process = $this->deptrac();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        /** @var array{Report: array<string, int>} $report */
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        foreach (['Violations', 'Uncovered', 'Errors', 'Warnings', 'Skipped violations'] as $counter) {
            self::assertSame(0, $report['Report'][$counter], $process->getOutput());
        }
        self::assertGreaterThan(0, $report['Report']['Allowed'], 'The fixture must establish actual dependency coverage.');
    }

    public function testDomainPortAndDoctrineAdapterHaveInwardDependencies(): void
    {
        $this->writeClass('App\\Module\\TaskTracking\\Domain\\TaskRepository', 'interface TaskRepository { public function add(Task $task): void; public function find(\\Symfony\\Component\\Uid\\Uuid $id): ?Task; }');
        $this->writeClass('App\\Module\\TaskTracking\\Application\\Handler', 'final class Handler { public function __construct(private \\App\\Module\\TaskTracking\\Domain\\TaskRepository $tasks) {} }');
        $this->writeClass('App\\Module\\TaskTracking\\Infrastructure\\Persistence\\DoctrineTaskRepository', <<<'PHP'
            use App\Module\TaskTracking\Domain\{Task, TaskRepository};
            use Doctrine\ORM\EntityManagerInterface;
            use Symfony\Component\Uid\Uuid;
            final class DoctrineTaskRepository implements TaskRepository {
                public function __construct(private EntityManagerInterface $em) {}
                public function add(Task $task): void { $this->em->persist($task); }
                public function find(Uuid $id): ?Task { return $this->em->find(Task::class, $id); }
            }
            PHP);
        $this->writeClass('App\\Module\\TaskTracking\\Domain\\Task', <<<'PHP'
            use Doctrine\ORM\Mapping as ORM;
            use Symfony\Bridge\Doctrine\Types\UuidType;
            use Symfony\Component\Uid\Uuid;
            #[ORM\Entity]
            final class Task { #[ORM\Id] #[ORM\Column(type: UuidType::NAME)] public Uuid $id; }
            PHP);
        self::assertSame([], (new SourceRules())->violations($this->root));
        $process = $this->deptrac();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    public function testEntityCannotReferenceInfrastructureViaRepositoryClassAttribute(): void
    {
        $this->writeClass('App\\Module\\TaskTracking\\Domain\\Task', <<<'PHP'
            use Doctrine\ORM\Mapping as ORM;
            use App\Module\TaskTracking\Infrastructure\Persistence\DoctrineTaskRepository;
            #[ORM\Entity(repositoryClass: DoctrineTaskRepository::class)]
            final class Task {}
            PHP);
        $process = $this->deptrac();
        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('TaskTracking.Domain on TaskTracking.Internal', $process->getOutput());
    }

    public function testMappingOverridesAllowTheirExactNestedDeclarationTypes(): void
    {
        $this->writeClass('App\\Module\\TaskTracking\\Domain\\BaseTask', <<<'PHP'
            use Doctrine\ORM\Mapping as ORM;
            #[ORM\MappedSuperclass]
            abstract class BaseTask {
                #[ORM\Id] #[ORM\Column] public int $id;
                #[ORM\Column] public string $title;
                #[ORM\ManyToOne(targetEntity: Tag::class)] public ?Tag $tag = null;
            }
            PHP);
        $this->writeClass('App\\Module\\TaskTracking\\Domain\\Tag', <<<'PHP'
            use Doctrine\ORM\Mapping as ORM;
            #[ORM\Entity] #[ORM\Table(name: 'task_tracking_tag', schema: 'public')]
            class Tag { #[ORM\Id] #[ORM\Column] public int $id; }
            PHP);
        $this->writeClass('App\\Module\\TaskTracking\\Domain\\Task', <<<'PHP'
            use Doctrine\ORM\Mapping as ORM;
            #[ORM\Entity] #[ORM\Table(name: 'task_tracking_task', schema: 'public')]
            #[ORM\AttributeOverrides([new ORM\AttributeOverride(name: 'title', column: new ORM\Column(length: 200))])]
            #[ORM\AssociationOverrides([new ORM\AssociationOverride(name: 'tag', joinColumns: [new ORM\JoinColumn(name: 'tag_id', referencedColumnName: 'id')])])]
            class Task extends BaseTask {}
            PHP);
        self::assertSame([], (new SourceRules())->violations($this->root));
        $process = $this->deptrac();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    /** @return iterable<string, array{string}> */
    public static function runtimeRepositoryTypes(): iterable
    {
        yield 'query API' => ['Doctrine\\ORM\\QueryBuilder'];
        yield 'metadata factory' => ['Doctrine\\ORM\\Mapping\\ClassMetadataFactory'];
        yield 'metadata' => ['Doctrine\\ORM\\Mapping\\ClassMetadata'];
        yield 'mapping driver' => ['Doctrine\\ORM\\Mapping\\Driver\\AttributeDriver'];
    }

    #[DataProvider('runtimeRepositoryTypes')]
    public function testDomainRepositoryInterfaceCannotExposeDoctrineRuntimeApi(string $type): void
    {
        $this->writeClass('App\\Module\\TaskTracking\\Domain\\TaskRepository', 'interface TaskRepository { public function query(): \\'.$type.'; }');
        $process = $this->deptrac();
        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('TaskTracking.Domain on PersistenceRuntime', $process->getOutput());
    }

    #[DataProvider('forbiddenDependencies')]
    public function testRealDeptracRejectsTheSpecificBoundary(string $source, string $target, string $layers): void
    {
        $shortName = substr($source, (int) strrpos($source, '\\') + 1);
        $this->writeClass($source, 'use \\'.$target.' as HiddenDependency; final readonly class '.$shortName.' { public function __construct(public HiddenDependency $value) {} }');
        $process = $this->deptrac();

        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertJson($process->getOutput());
        self::assertStringContainsString(
            json_encode($source.' must not depend on '.$target.' ('.$layers.')', JSON_THROW_ON_ERROR),
            $process->getOutput(),
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function forbiddenDependencies(): iterable
    {
        yield 'foreign internal' => ['App\\Module\\TaskTracking\\Application\\Leak', 'App\\Module\\Authorizing\\Domain\\Grant', 'TaskTracking.Application on Authorizing.Domain'];
        yield 'case variation cannot masquerade as vendor' => ['App\\Module\\TaskTracking\\Application\\Leak', 'app\\module\\authorizing\\domain\\Grant', 'TaskTracking.Application on Authorizing.Domain'];
        yield 'own internal in public application data' => ['App\\Module\\TaskTracking\\Application\\Leak\\LeakResult', 'App\\Module\\TaskTracking\\Domain\\Task', 'TaskTracking.ApplicationData on TaskTracking.Domain'];
        yield 'foreign internal in public application data' => ['App\\Module\\TaskTracking\\Application\\Leak\\LeakResult', 'App\\Module\\Authorizing\\Domain\\Grant', 'TaskTracking.ApplicationData on Authorizing.Domain'];
        yield 'newly discovered module cannot bypass rules' => ['App\\Module\\Auditing\\Application\\Leak', 'App\\Module\\TaskTracking\\Domain\\Task', 'Auditing.Application on TaskTracking.Domain'];
        yield 'Platform cannot reach internals' => ['App\\Platform\\Leak', 'App\\Module\\TaskTracking\\Domain\\Task', 'Platform on TaskTracking.Domain'];
        yield 'Kernel exception is boot only' => ['App\\Kernel', 'App\\Module\\TaskTracking\\Domain\\Task', 'KernelBoot on TaskTracking.Domain'];
        yield 'unclassified source is not vendor' => ['App\\Service\\Leak', 'App\\Module\\TaskTracking\\Domain\\Task', 'Unclassified on TaskTracking.Domain'];
        yield 'public data cannot expose mutable vendor value' => ['App\\Module\\TaskTracking\\Application\\Leak\\LeakResult', 'DateTime', 'TaskTracking.ApplicationData on Vendor'];
        yield 'public data cannot expose Platform' => ['App\\Module\\TaskTracking\\Application\\Leak\\LeakResult', 'App\\Platform\\Repository', 'TaskTracking.ApplicationData on Platform'];
        foreach (['Command', 'Query', 'Result', 'Input'] as $kind) {
            yield 'Domain cannot depend on own '.$kind => ['App\\Module\\TaskTracking\\Domain\\Leak', 'App\\Module\\TaskTracking\\Application\\Lookup\\Lookup'.$kind, 'TaskTracking.Domain on TaskTracking.ApplicationData'];
            yield 'Domain cannot depend on foreign '.$kind => ['App\\Module\\TaskTracking\\Domain\\Leak', 'App\\Module\\Authorizing\\Application\\Lookup\\Lookup'.$kind, 'TaskTracking.Domain on Authorizing.ApplicationData'];
            yield 'event cannot expose Application '.$kind => ['App\\Module\\TaskTracking\\Application\\Leak\\LeakEvent', 'App\\Module\\Authorizing\\Application\\Lookup\\Lookup'.$kind, 'TaskTracking.EventData on Authorizing.ApplicationData'];
        }
        yield 'foreign co-located handler' => ['App\\Module\\TaskTracking\\Application\\Leak', 'App\\Module\\Authorizing\\Application\\GetGrant\\GetGrantHandler', 'TaskTracking.Application on Authorizing.Handler'];
        yield 'foreign helper in a use case' => ['App\\Module\\TaskTracking\\Application\\Leak', 'App\\Module\\Authorizing\\Application\\GetGrant\\Formatter', 'TaskTracking.Application on Authorizing.Application'];
        yield 'public data cannot expose its co-located handler' => ['App\\Module\\TaskTracking\\Application\\Lookup\\LookupResult', 'App\\Module\\TaskTracking\\Application\\Lookup\\LookupHandler', 'TaskTracking.ApplicationData on TaskTracking.Handler'];
        yield 'case variation cannot hide public data dependency from Domain' => ['App\\Module\\TaskTracking\\Domain\\Leak', 'app\\module\\authorizing\\application\\GetGrant\\GetGrantResult', 'TaskTracking.Domain on Authorizing.ApplicationData'];
        yield 'no blanket Platform access' => ['App\\Module\\TaskTracking\\Application\\Leak', 'App\\Platform\\Repository', 'TaskTracking.Application on Platform'];
        yield 'Domain has no blanket Platform access' => ['App\\Module\\TaskTracking\\Domain\\Leak', 'App\\Platform\\Repository', 'TaskTracking.Domain on Platform'];
        yield 'recording directory is not a blanket exception' => ['App\\Module\\TaskTracking\\Domain\\Leak', 'App\\Platform\\Event\\Recording\\OtherSupport', 'TaskTracking.Domain on Platform'];
        foreach (['RecordsDomainEvents', 'RecordsDomainEventsTrait'] as $support) {
            $target = 'App\\Platform\\Event\\Recording\\'.$support;
            foreach (['Application\\Leak' => 'Application', 'Application\\Leak\\LeakResult' => 'ApplicationData', 'Application\\Leak\\LeakEvent' => 'EventData', 'Domain\\Event\\LeakEvent' => 'DomainEventData', 'Infrastructure\\Event\\LeakEvent' => 'InfrastructureEventData', 'Infrastructure\\Leak' => 'Internal', 'Infrastructure\\EventListener\\LeakListener' => 'EventListener', 'Infrastructure\\Framework\\Doctrine\\EventListener\\Leak' => 'FrameworkEventListener', 'UI\\Http\\Leak' => 'UI'] as $source => $layer) {
                yield $source.' cannot consume '.$support => ['App\\Module\\TaskTracking\\'.$source, $target, 'TaskTracking.'.$layer.' on DomainEventRecording'];
            }
        }
        foreach (['App\\Platform\\Repository' => 'Platform', 'App\\Platform\\Event\\BaseEvent' => 'BaseEvent', 'App\\Platform\\Event\\ApplicationEvent' => 'ApplicationEvent', 'App\\Platform\\Event\\InfrastructureEvent' => 'InfrastructureEvent', 'App\\Module\\TaskTracking\\Domain\\Task' => 'TaskTracking.Domain', 'DateTimeImmutable' => 'ImmutableValues', 'Psr\\Log\\LoggerInterface' => 'Vendor'] as $target => $layer) {
            yield 'recording support cannot consume '.$target => ['App\\Platform\\Event\\Recording\\RecordsDomainEventsTrait', $target, 'DomainEventRecording on '.$layer];
        }
        yield 'raw Messenger bus' => ['App\\Module\\TaskTracking\\Application\\Leak', 'Symfony\\Component\\Messenger\\MessageBusInterface', 'TaskTracking.Application on MessagingRuntime'];
        yield 'raw handler locator' => ['App\\Module\\TaskTracking\\UI\\Http\\Leak', 'Symfony\\Component\\Messenger\\Handler\\HandlersLocatorInterface', 'TaskTracking.UI on MessagingRuntime'];
        yield 'Domain cannot dispatch' => ['App\\Module\\TaskTracking\\Domain\\Leak', 'App\\Platform\\Messaging\\CommandBus', 'TaskTracking.Domain on MessagingFacades'];
        yield 'Infrastructure cannot dispatch' => ['App\\Module\\TaskTracking\\Infrastructure\\Leak', 'App\\Platform\\Messaging\\QueryBus', 'TaskTracking.Internal on QueryBus'];
        yield 'invocation context remains private' => ['App\\Module\\TaskTracking\\Application\\Leak', 'App\\Platform\\Messaging\\InvocationContext', 'TaskTracking.Application on Platform'];
        yield 'public DTO cannot dispatch' => ['App\\Module\\TaskTracking\\Application\\Leak\\LeakResult', 'App\\Platform\\Messaging\\QueryBus', 'TaskTracking.ApplicationData on QueryBus'];
        foreach (['Infrastructure\\Persistence\\DoctrineTaskRepository', 'Application\\Handler', 'UI\\Http\\Controller'] as $target) {
            yield 'domain must not depend on '.$target => ['App\\Module\\TaskTracking\\Domain\\Leak', 'App\\Module\\TaskTracking\\'.$target, 'TaskTracking.Domain on TaskTracking.'.(str_starts_with($target, 'Application') ? 'Handler' : (str_starts_with($target, 'UI') ? 'UI' : 'Internal'))];
        }
        yield 'application concrete repository' => ['App\\Module\\TaskTracking\\Application\\Leak', 'App\\Module\\TaskTracking\\Infrastructure\\Persistence\\DoctrineTaskRepository', 'TaskTracking.Application on TaskTracking.Internal'];
        yield 'domain runtime ORM type' => ['App\\Module\\TaskTracking\\Domain\\Leak', 'Doctrine\\ORM\\EntityManagerInterface', 'TaskTracking.Domain on PersistenceRuntime'];
        yield 'domain Doctrine collection' => ['App\\Module\\TaskTracking\\Domain\\Leak', 'Doctrine\\Common\\Collections\\Collection', 'TaskTracking.Domain on PersistenceRuntime'];
        yield 'application DBAL shortcut' => ['App\\Module\\TaskTracking\\Application\\Leak', 'Doctrine\\DBAL\\Connection', 'TaskTracking.Application on PersistenceRuntime'];
        foreach (['Domain', 'Infrastructure'] as $category) {
            yield 'foreign internal '.$category.' event' => ['App\\Module\\TaskTracking\\Application\\Leak', 'App\\Module\\Authorizing\\'.$category.'\\Event\\ObservedEvent', 'TaskTracking.Application on Authorizing.'.$category.'EventData'];
            yield 'public payload cannot contain '.$category.' event' => ['App\\Module\\TaskTracking\\Application\\Observe\\ObservedEvent', 'App\\Module\\TaskTracking\\'.$category.'\\Event\\ObservedEvent', 'TaskTracking.EventData on TaskTracking.'.$category.'EventData'];
            yield $category.' event cannot contain own entity' => ['App\\Module\\TaskTracking\\'.$category.'\\Event\\ObservedEvent', 'App\\Module\\TaskTracking\\Domain\\Task', 'TaskTracking.'.$category.'EventData on TaskTracking.Domain'];
            yield $category.' event cannot depend on public Application event' => ['App\\Module\\TaskTracking\\'.$category.'\\Event\\ObservedEvent', 'App\\Module\\TaskTracking\\Application\\Observe\\ObservedEvent', 'TaskTracking.'.$category.'EventData on TaskTracking.EventData'];
        }
        yield 'Domain cannot depend on public Application event' => ['App\\Module\\TaskTracking\\Domain\\Leak', 'App\\Module\\TaskTracking\\Application\\Observe\\ObservedEvent', 'TaskTracking.Domain on TaskTracking.EventData'];
        foreach (['Base', 'Application', 'Infrastructure'] as $category) {
            yield 'Domain cannot use '.$category.' primitive' => ['App\\Module\\TaskTracking\\Domain\\Leak', 'App\\Platform\\Event\\'.$category.'Event', 'TaskTracking.Domain on '.$category.'Event'];
        }
        foreach (['Domain\\Task' => 'Domain', 'Application\\Observe\\ObserveHandler' => 'Handler', 'Infrastructure\\Repository' => 'Internal', 'Domain\\Event\\ObservedEvent' => 'DomainEventData', 'Infrastructure\\Event\\ObservedEvent' => 'InfrastructureEventData'] as $target => $layer) {
            yield 'own listener cannot reach '.$target => ['App\\Module\\TaskTracking\\Infrastructure\\EventListener\\ObservedListener', 'App\\Module\\TaskTracking\\'.$target, 'TaskTracking.EventListener on TaskTracking.'.$layer];
        }
        foreach (['Doctrine\\ORM\\EntityManagerInterface' => 'PersistenceRuntime', 'Symfony\\Component\\Messenger\\MessageBusInterface' => 'MessagingRuntime', 'Symfony\\Component\\Messenger\\Exception\\ValidationFailedException' => 'MessagingDeclarations', 'Psr\\Log\\LoggerInterface' => 'Vendor', 'App\\Platform\\Messaging\\EventBus' => 'EventBus', 'App\\Platform\\Messaging\\InvocationContext' => 'Platform'] as $target => $layer) {
            yield 'own listener restricted dependency '.$target => ['App\\Module\\TaskTracking\\Infrastructure\\EventListener\\ObservedListener', $target, 'TaskTracking.EventListener on '.$layer];
        }
        foreach (['UI\\Http\\Leak' => 'UI', 'Infrastructure\\Leak' => 'Internal', 'Domain\\Leak' => 'Domain', 'Application\\Observe\\ObservedEvent' => 'EventData', 'Application\\Observe\\ObserveResult' => 'ApplicationData'] as $source => $layer) {
            yield 'event bus only Application implementation '.$source => ['App\\Module\\TaskTracking\\'.$source, 'App\\Platform\\Messaging\\EventBus', 'TaskTracking.'.$layer.' on EventBus'];
        }
        yield 'framework listener has no own listener facade permission' => ['App\\Module\\TaskTracking\\Infrastructure\\Framework\\Doctrine\\EventListener\\FlushListener', 'App\\Platform\\Messaging\\CommandBus', 'TaskTracking.FrameworkEventListener on MessagingFacades'];
    }

    public function testUnusedForeignInternalImportIsStillADependency(): void
    {
        $this->writeClass('App\\Module\\TaskTracking\\Application\\Leak', 'use App\Module\Authorizing\Domain\Grant; final class Leak {}');
        $process = $this->deptrac();
        self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString(
            json_encode('App\\Module\\TaskTracking\\Application\\Leak must not depend on App\\Module\\Authorizing\\Domain\\Grant (TaskTracking.Application on Authorizing.Domain)', JSON_THROW_ON_ERROR),
            $process->getOutput(),
        );
    }

    public function testPolicyDeclarationAndReadDependenciesPassBothSourceAndDeptrac(): void
    {
        $this->writeClass('App\\Platform\\Authorization\\ActorKind', 'enum ActorKind { case Anonymous; case Account; case Operator; case Authentication; }');
        $this->writeClass('App\\Platform\\Authorization\\Actor', 'final readonly class Actor { public function __construct(public ActorKind $kind) {} }');
        $this->writeClass('App\\Module\\TaskTracking\\Domain\\TaskRepository', 'interface TaskRepository { public function find(): ?Task; }');
        $this->writeClass('App\\Module\\TaskTracking\\Application\\Lookup\\LookupQuery', 'final readonly class LookupQuery {}');
        $this->writeClass('App\\Module\\TaskTracking\\Application\\Lookup\\LookupPolicy', <<<'PHP'
            use App\Platform\Authorization\{Actor, ActorKind as Kind, PolicyContext};
            use App\Platform\Messaging\QueryBus;
            use App\Module\TaskTracking\Domain\TaskRepository;
            use App\Module\Authorizing\Application\GetGrant\GetGrantResult;
            final readonly class LookupPolicy {
                public function __construct(private QueryBus $queries, private TaskRepository $tasks) {}
                public function __invoke(LookupQuery $query, PolicyContext $context): bool {
                    $actor = $context->actor;
                    if (!$actor instanceof Actor) { throw new \LogicException('Missing actor.'); }
                    return Kind::Account === $actor->kind && $this->tasks->find() !== null;
                }
            }
            PHP);
        $this->writeClass('App\\Module\\TaskTracking\\Application\\Lookup\\LookupHandler', <<<'PHP'
            use App\Platform\Authorization\AuthorizeWith as Admission;
            use App\Module\TaskTracking\Application\Lookup\{LookupPolicy as Policy};
            use Symfony\Component\Messenger\Attribute\AsMessageHandler;
            #[AsMessageHandler(bus: 'query.bus')]
            #[Admission(Policy::class)]
            final class LookupHandler { public function __invoke(LookupQuery $query): bool { return true; } }
            PHP);
        self::assertSame([], (new SourceRules())->violations($this->root));
        $process = $this->deptrac();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    public function testDeclaredActorKindEnumIsNotPublicApplicationData(): void
    {
        $this->writeClass('App\\Platform\\Authorization\\ActorKind', 'enum ActorKind { case Anonymous; case Account; case Operator; case Authentication; }');
        $class = 'App\\Module\\TaskTracking\\Application\\Lookup\\LookupResult';
        $this->writeClass($class, 'use App\\Platform\\Authorization\\ActorKind; final readonly class LookupResult { public function __construct(public ActorKind|string|null $kind) {} }');

        $this->assertSourceDiagnostic('authorization.context', $class);
        $this->assertSourceDiagnostic('contract.type', $class);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function forbiddenAuthorizationSource(): iterable
    {
        foreach (['Application\\Lookup\\LookupHandler', 'Application\\Helper', 'UI\\Http\\Controller', 'Infrastructure\\Adapter', 'Domain\\Service'] as $source) {
            $name = substr($source, (int) strrpos($source, '\\') + 1);
            yield 'handler bypass from '.$source => [$source, 'use App\\Module\\TaskTracking\\Application\\Other\\OtherHandler as Other; final class '.$name.' { public function __construct(private Other $other) {} }', 'source.handler_dependency'];
        }
        foreach ([
            'new LookupPolicy()',
            'LookupPolicy::decide()',
            '$factory = LookupPolicy::class',
        ] as $operation) {
            yield 'attribute does not permit '.$operation => ['Application\\Lookup\\LookupHandler', '#[\\App\\Platform\\Authorization\\AuthorizeWith(LookupPolicy::class)] final class LookupHandler { public function call(): void { '.$operation.'; } }', 'authorization.policy_reference'];
        }
        yield 'policy instance with valid metadata' => ['Application\\Lookup\\LookupHandler', '#[\\App\\Platform\\Authorization\\AuthorizeWith(LookupPolicy::class)] final class LookupHandler { public function __construct(private LookupPolicy $policy) {} }', 'authorization.policy_reference'];
        yield 'policy in unrelated attribute' => ['Application\\Lookup\\LookupHandler', '#[\\Other(LookupPolicy::class)] final class LookupHandler {}', 'authorization.policy_reference'];
        yield 'declaration on helper' => ['Application\\Lookup\\Helper', '#[\\App\\Platform\\Authorization\\AuthorizeWith(LookupPolicy::class)] final class Helper {}', 'authorization.declaration'];
        yield 'declaration as handler runtime data' => ['Application\\Lookup\\LookupHandler', 'final class LookupHandler { public function __construct(private \\App\\Platform\\Authorization\\AuthorizeWith $attribute) {} }', 'authorization.declaration'];
        yield 'nested policy path' => ['Application\\Lookup\\Nested\\LookupPolicy', 'final class LookupPolicy {}', 'authorization.policy_path'];
        foreach (['Actor', 'ActorKind', 'PolicyContext'] as $type) {
            yield 'context in DTO '.$type => ['Application\\Lookup\\LookupQuery', 'final readonly class LookupQuery { public function __construct(public \\App\\Platform\\Authorization\\'.$type.' $actor) {} }', 'authorization.context'];
            yield 'context in handler '.$type => ['Application\\Lookup\\LookupHandler', 'use App\\Platform\\Authorization\\'.$type.'; final class LookupHandler {}', 'authorization.context'];
        }
        foreach (['Application\\Lookup\\LookupHandler', 'Application\\Helper', 'UI\\Http\\Controller', 'Infrastructure\\Adapter', 'Domain\\Service'] as $source) {
            $name = substr($source, (int) strrpos($source, '\\') + 1);
            yield 'actor kind case in '.$source => [$source, 'use App\\Platform\\Authorization\\{ActorKind as Kind}; final class '.$name.' { public function run(): void { $kind = Kind::Operator; } }', 'authorization.context'];
        }
        yield 'actor kind case variation' => ['Application\\Helper', 'final class Helper { public function run(): void { $kind = \\app\\platform\\authorization\\actorkind::Account; } }', 'authorization.context'];
        foreach (['CommandBus', 'EventBus', 'InvocationContext'] as $type) {
            yield 'policy messaging '.$type => ['Application\\Lookup\\LookupPolicy', 'use App\\Platform\\Messaging\\'.$type.'; final class LookupPolicy {}', 'authorization.policy_dependency'];
        }
        foreach (['\\App\\Module\\TaskTracking\\Application\\Helper', '\\App\\Module\\TaskTracking\\Application\\Other\\OtherPolicy', '\\Doctrine\\ORM\\EntityManagerInterface', '\\Symfony\\Component\\HttpFoundation\\RequestStack', '\\Psr\\Container\\ContainerInterface', '\\Psr\\Log\\LoggerInterface', '\\App\\Module\\Authorizing\\Domain\\Grant'] as $target) {
            yield 'policy dependency '.$target => ['Application\\Lookup\\LookupPolicy', 'use '.$target.' as Proxy; final class LookupPolicy { public function __construct(private Proxy $proxy) {} }', 'authorization.policy_dependency'];
        }
        foreach (['OperatorExecution', 'AuthenticationExecution', 'ExecutionContext'] as $type) {
            yield 'untrusted facade '.$type => ['UI\\Console\\OtherCommand', 'use App\\Platform\\Authorization\\'.$type.'; final class OtherCommand {}', 'authorization.runtime'];
        }
        yield 'denial construction' => ['UI\\Http\\Controller', 'final class Controller { public function run(): void { throw new \\App\\Platform\\Authorization\\AuthorizationDenied(true); } }', 'authorization.denied'];
        yield 'handler catch denial' => ['Application\\Lookup\\LookupHandler', 'final class LookupHandler { public function run(): void { try {} catch (\\App\\Platform\\Authorization\\AuthorizationDenied $error) {} } }', 'authorization.denied'];
    }

    #[DataProvider('forbiddenAuthorizationSource')]
    public function testAuthorizationSyntaxCannotBypassSourceBoundaries(string $suffix, string $declaration, string $rule): void
    {
        $class = 'App\\Module\\TaskTracking\\'.$suffix;
        $this->writeClass($class, $declaration);
        $this->assertSourceDiagnostic($rule, $class);
    }

    public function testExactExecutionAdaptersAndUiDenialCatchAreAccepted(): void
    {
        foreach (\App\Platform\Architecture\ContractTypes::executionFacadeConsumers() as $facade => $consumers) {
            foreach ($consumers as $consumer) {
                $name = substr($consumer, (int) strrpos($consumer, '\\') + 1);
                $this->writeClass($consumer, 'final class '.$name.' { public function __construct(private \\'.$facade.' $execution) {} }');
            }
        }
        $this->writeClass('App\\Module\\TaskTracking\\UI\\Http\\Controller', 'use App\\Platform\\Authorization\\AuthorizationDenied; final class Controller { public function run(): void { try {} catch (AuthorizationDenied $denied) {} } }');
        self::assertSame([], (new SourceRules())->violations($this->root));
        $process = $this->deptrac();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function forbiddenAuthorizationDependencies(): iterable
    {
        $policy = 'App\\Module\\TaskTracking\\Application\\Lookup\\LookupPolicy';
        foreach ([
            'App\\Platform\\Messaging\\CommandBus' => 'MessagingFacades',
            'App\\Platform\\Messaging\\EventBus' => 'EventBus',
            'App\\Platform\\Messaging\\InvocationContext' => 'Platform',
            'App\\Platform\\Authorization\\ExecutionContext' => 'Platform',
            'App\\Module\\TaskTracking\\Application\\Helper' => 'TaskTracking.Application',
            'App\\Module\\TaskTracking\\Application\\Lookup\\LookupHandler' => 'TaskTracking.Handler',
            'App\\Module\\TaskTracking\\Infrastructure\\Repository' => 'TaskTracking.Internal',
            'App\\Module\\Authorizing\\Domain\\Grant' => 'Authorizing.Domain',
            'Doctrine\\ORM\\EntityManagerInterface' => 'PersistenceRuntime',
            'Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler' => 'MessageHandlerAttribute',
            'Symfony\\Component\\Messenger\\Exception\\ValidationFailedException' => 'MessagingDeclarations',
            'Psr\\Log\\LoggerInterface' => 'Vendor',
            'Psr\\Container\\ContainerInterface' => 'Vendor',
            'Symfony\\Component\\HttpFoundation\\RequestStack' => 'Vendor',
        ] as $target => $layer) {
            yield $target => [$policy, $target, 'TaskTracking.Policy on '.$layer];
        }
        yield 'module handler bypass' => ['App\\Module\\TaskTracking\\UI\\Http\\Controller', 'App\\Module\\TaskTracking\\Application\\Lookup\\LookupHandler', 'TaskTracking.UI on TaskTracking.Handler'];
        yield 'Platform cannot inject policies' => ['App\\Platform\\Technical', $policy, 'Platform on TaskTracking.Policy'];
        yield 'context is not public DTO data' => ['App\\Module\\TaskTracking\\Application\\Lookup\\LookupQuery', 'App\\Platform\\Authorization\\Actor', 'TaskTracking.ApplicationData on PolicyContext'];
        foreach (['Application\\Lookup\\LookupQuery' => 'ApplicationData', 'Application\\Lookup\\LookupHandler' => 'Handler', 'Application\\Helper' => 'Application', 'Domain\\Service' => 'Domain', 'Infrastructure\\Adapter' => 'Internal', 'UI\\Http\\Controller' => 'UI'] as $source => $layer) {
            yield 'actor kind in '.$source => ['App\\Module\\TaskTracking\\'.$source, 'App\\Platform\\Authorization\\ActorKind', 'TaskTracking.'.$layer.' on PolicyContext'];
        }
        yield 'facade is exact adapter only' => ['App\\Module\\TaskTracking\\UI\\Console\\OtherCommand', 'App\\Platform\\Authorization\\OperatorExecution', 'TaskTracking.UI on OperatorExecution'];
        yield 'operator cannot claim authentication scope' => ['App\\Module\\TaskTracking\\UI\\Console\\CreateTaskConsoleCommand', 'App\\Platform\\Authorization\\AuthenticationExecution', 'TaskTracking.OperatorAdapter on AuthenticationExecution'];
        yield 'handler has no context' => ['App\\Module\\TaskTracking\\Application\\Lookup\\LookupHandler', 'App\\Platform\\Authorization\\PolicyContext', 'TaskTracking.Handler on PolicyContext'];
        yield 'helper cannot declare admission' => ['App\\Module\\TaskTracking\\Application\\Lookup\\Helper', 'App\\Platform\\Authorization\\AuthorizeWith', 'TaskTracking.Application on AuthorizeWith'];
    }

    #[DataProvider('forbiddenAuthorizationDependencies')]
    public function testDeptracAuthorizationLayersRemainNarrow(string $source, string $target, string $layers): void
    {
        $this->testRealDeptracRejectsTheSpecificBoundary($source, $target, $layers);
    }

    private function assertSourceDiagnostic(string $rule, string $offender): void
    {
        $violations = (new SourceRules())->violations($this->root);
        foreach ($violations as $violation) {
            if (str_starts_with($violation, $rule.':') && str_contains($violation, $offender)) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        self::fail('Missing '.$rule.' diagnostic for '.$offender.". Actual diagnostics:\n".implode("\n", $violations));
    }

    private function writeClass(string $class, string $declaration): void
    {
        $split = strrpos($class, '\\');
        self::assertNotFalse($split);
        $this->write('src/'.str_replace('\\', '/', substr($class, 4)).'.php', '<?php namespace '.substr($class, 0, $split).'; '.$declaration);
    }

    private function write(string $path, string $source): void
    {
        $this->filesystem->dumpFile($this->root.'/'.$path, $source);
    }

    private function deptrac(): Process
    {
        $projectDir = \dirname(__DIR__, 2);
        // The disposable root has no Composer project. Load only the shared
        // tooling from the real project, never the fixture application classes.
        $bootstrap = '<?php require_once '.var_export($projectDir.'/src/Platform/Architecture/ContractTypes.php', true).';'
            .' require_once '.var_export($projectDir.'/tools/Architecture/DeptracRules.php', true).';';
        $this->write('deptrac.php', $bootstrap.<<<'PHP'
            return static function (\Deptrac\Deptrac\Contract\Config\DeptracConfig $config): void {
                \App\Tools\Architecture\DeptracRules::configure($config, __DIR__);
            };
            PHP);
        $process = new Process([
            PHP_BINARY, $projectDir.'/vendor/bin/deptrac', 'analyse',
            '--config-file='.$this->root.'/deptrac.php', '--no-cache', '--no-progress',
            '--no-ansi', '--formatter=json', '--report-uncovered', '--fail-on-uncovered',
        ], $projectDir, timeout: 30);
        $process->run();

        return $process;
    }
}
