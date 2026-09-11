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
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testDataContractsAndModuleResourcesAreAcceptedWithoutExecutingSource(): void
    {
        $this->writeClass('App\\Module\\TaskTracking\\Contract\\Event\\Status', "enum Status: string { case Open = 'open'; case Closed = 'closed'; }");
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
        foreach (['Command', 'Query'] as $kind) {
            $class = 'App\\Module\\TaskTracking\\Application\\Example\\Example'.$kind;
            $this->writeClass($class, 'final class Example'.$kind.' {}');
            $this->assertSourceDiagnostic('contract.shape', $class);
        }
        $event = 'App\\Module\\TaskTracking\\Contract\\Event\\Happened';
        $this->writeClass($event, 'final readonly class Happened { public function handle(): void {} }');
        $this->assertSourceDiagnostic('contract.behavior', $event);
    }

    public function testEventPayloadCannotIntroduceAnIndirectApplicationDependency(): void
    {
        $event = 'App\\Module\\TaskTracking\\Contract\\Event\\Happened';
        $this->writeClass($event, 'final readonly class Happened { public function __construct(public string|\App\Module\Authorizing\Application\GetGrant\GetGrantResult|null $payload) {} }');
        $this->assertSourceDiagnostic('contract.type', $event);
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
        foreach (['Command', 'Query', 'Result'] as $kind) {
            yield 'obsolete '.$kind.' location' => ['src/Module/TaskTracking/Contract/'.$kind.'/Example.php', 'App\\Module\\TaskTracking\\Contract\\'.$kind.'\\Example', 'contract.path'];
            yield 'missing use case for '.$kind => ['src/Module/TaskTracking/Application/Example'.$kind.'.php', 'App\\Module\\TaskTracking\\Application\\Example'.$kind, 'contract.path'];
            yield 'nested '.$kind.' facade' => ['src/Module/TaskTracking/Application/Example/Service/Example'.$kind.'.php', 'App\\Module\\TaskTracking\\Application\\Example\\Service\\Example'.$kind, 'contract.path'];
        }
        yield 'missing descriptive prefix' => ['src/Module/TaskTracking/Application/Example/Result.php', 'App\\Module\\TaskTracking\\Application\\Example\\Result', 'contract.path'];
        yield 'internal namespace hidden in application data path' => ['src/Module/TaskTracking/Application/Example/ExampleResult.php', 'App\\Module\\TaskTracking\\Domain\\Example', 'contract.path'];
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
        $this->writeClass('App\\Module\\Auditing\\Contract\\Event\\Recorded', 'final readonly class Recorded { public function __construct(public \DateTimeImmutable $at, public \Symfony\Component\Uid\Uuid $id) {} }');
        $this->writeClass('App\\Module\\TaskTracking\\Contract\\Event\\Linked', 'final readonly class Linked { public function __construct(public \App\Module\Auditing\Contract\Event\Recorded $event) {} }');
        $this->writeClass('App\\Module\\TaskTracking\\Domain\\EventConsumer', 'final class EventConsumer { public function consume(\App\Module\TaskTracking\Contract\Event\Linked $event): void {} }');
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
        foreach (['Command', 'Query', 'Result'] as $kind) {
            yield 'Domain cannot depend on own '.$kind => ['App\\Module\\TaskTracking\\Domain\\Leak', 'App\\Module\\TaskTracking\\Application\\Lookup\\Lookup'.$kind, 'TaskTracking.Domain on TaskTracking.ApplicationData'];
            yield 'Domain cannot depend on foreign '.$kind => ['App\\Module\\TaskTracking\\Domain\\Leak', 'App\\Module\\Authorizing\\Application\\Lookup\\Lookup'.$kind, 'TaskTracking.Domain on Authorizing.ApplicationData'];
            yield 'event cannot expose Application '.$kind => ['App\\Module\\TaskTracking\\Contract\\Event\\Leak', 'App\\Module\\Authorizing\\Application\\Lookup\\Lookup'.$kind, 'TaskTracking.EventData on Authorizing.ApplicationData'];
        }
        yield 'foreign co-located handler' => ['App\\Module\\TaskTracking\\Application\\Leak', 'App\\Module\\Authorizing\\Application\\GetGrant\\GetGrantHandler', 'TaskTracking.Application on Authorizing.Application'];
        yield 'foreign helper in a use case' => ['App\\Module\\TaskTracking\\Application\\Leak', 'App\\Module\\Authorizing\\Application\\GetGrant\\Formatter', 'TaskTracking.Application on Authorizing.Application'];
        yield 'public data cannot expose its co-located handler' => ['App\\Module\\TaskTracking\\Application\\Lookup\\LookupResult', 'App\\Module\\TaskTracking\\Application\\Lookup\\LookupHandler', 'TaskTracking.ApplicationData on TaskTracking.Application'];
        yield 'case variation cannot hide public data dependency from Domain' => ['App\\Module\\TaskTracking\\Domain\\Leak', 'app\\module\\authorizing\\application\\GetGrant\\GetGrantResult', 'TaskTracking.Domain on Authorizing.ApplicationData'];
        yield 'no blanket Platform access' => ['App\\Module\\TaskTracking\\Application\\Leak', 'App\\Platform\\Repository', 'TaskTracking.Application on Platform'];
        foreach (['Infrastructure\\Persistence\\DoctrineTaskRepository', 'Application\\Handler', 'UI\\Http\\Controller'] as $target) {
            yield 'domain must not depend on '.$target => ['App\\Module\\TaskTracking\\Domain\\Leak', 'App\\Module\\TaskTracking\\'.$target, 'TaskTracking.Domain on TaskTracking.'.(str_starts_with($target, 'Application') ? 'Application' : 'Internal')];
        }
        yield 'application concrete repository' => ['App\\Module\\TaskTracking\\Application\\Leak', 'App\\Module\\TaskTracking\\Infrastructure\\Persistence\\DoctrineTaskRepository', 'TaskTracking.Application on TaskTracking.Internal'];
        yield 'domain runtime ORM type' => ['App\\Module\\TaskTracking\\Domain\\Leak', 'Doctrine\\ORM\\EntityManagerInterface', 'TaskTracking.Domain on PersistenceRuntime'];
        yield 'domain Doctrine collection' => ['App\\Module\\TaskTracking\\Domain\\Leak', 'Doctrine\\Common\\Collections\\Collection', 'TaskTracking.Domain on PersistenceRuntime'];
        yield 'application DBAL shortcut' => ['App\\Module\\TaskTracking\\Application\\Leak', 'Doctrine\\DBAL\\Connection', 'TaskTracking.Application on PersistenceRuntime'];
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
