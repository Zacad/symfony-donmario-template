<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Platform\Architecture\ContractTypes;
use App\Tests\Fixtures\CollectionContracts\CollectionFixture;
use App\Tools\Architecture\SourceRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CollectionSourceTest extends TestCase
{
    private CollectionFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new CollectionFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->close();
    }

    public function testListsResolveImportsAndDescribeTransitiveNestedDtoCascadesWithoutAutoloading(): void
    {
        $namespace = substr($this->fixture->name('LeafInput'), 0, (int) strrpos($this->fixture->name('LeafInput'), '\\'));
        $this->fixture->write('StateInput', "enum StateInput: string { case Ready = 'ready'; }");
        $this->fixture->write('NestedInput', 'final readonly class NestedInput { /** @param list<string> $labels */ public function __construct(public array $labels = []) {} }');
        $this->fixture->write('BatchCommand', str_replace('__NAMESPACE__', $namespace, <<<'PHP'
            use Symfony\Component\Uid\{Uuid as Identity};
            use DateTimeImmutable as Instant;
            use __NAMESPACE__ as Data;
            use __NAMESPACE__\{LeafInput as Leaf};
            final readonly class BatchCommand {
                /**
                 * @param list<string> $strings
                 * @param list<int> $integers
                 * @param list<float> $floats
                 * @param list<bool> $booleans
                 * @param list<Identity> $ids
                 * @param list<Instant> $times
                 * @param list<Leaf> $leaves
                 * @param list<Data\NestedInput> $nested
                 * @param list<namespace\StateInput> $states
                 */
                public function __construct(
                    public array $strings,
                    public array $integers,
                    public array $floats,
                    public array $booleans,
                    /** @var list<\Symfony\Component\Uid\Uuid> */ public array $ids,
                    public array $times,
                    /** @var list<Leaf> $leaves */ public array $leaves,
                    public array $nested,
                    public array $states = [],
                ) {}
            }
            PHP));
        $this->fixture->write('WrapperResult', 'final readonly class WrapperResult { public function __construct(public ?BatchCommand $batch) {} }');
        $this->fixture->write('RootQuery', 'final readonly class RootQuery { public function __construct(public WrapperResult|LeafInput|null $wrapper) {} }');
        $contracts = $this->fixture->contracts();
        self::assertSame(['type' => 'Symfony\\Component\\Uid\\Uuid', 'dto' => false], $contracts->lists[$this->fixture->name('BatchCommand')]['ids']);
        self::assertSame(['type' => $this->fixture->name('LeafInput'), 'dto' => true], $contracts->lists[$this->fixture->name('BatchCommand')]['leaves']);
        self::assertSame(['type' => $this->fixture->name('StateInput'), 'dto' => false], $contracts->lists[$this->fixture->name('BatchCommand')]['states']);
        self::assertSame(['leaves', 'nested'], $contracts->cascades[$this->fixture->name('BatchCommand')]);
        self::assertSame(['batch'], $contracts->cascades[$this->fixture->name('WrapperResult')]);
        self::assertSame(['wrapper'], $contracts->cascades[$this->fixture->name('RootQuery')]);
        self::assertFalse(class_exists($this->fixture->name('BatchCommand'), false));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTypes(): iterable
    {
        foreach (['array<string>', 'array<int, string>', 'string[]', 'non-empty-list<string>', 'list', 'list<>', 'list<string, int>', '?list<string>', 'list<string>|null', 'list<string|int>', 'list<?string>', 'list<list<string>>', 'list<array<string>>', 'list<mixed>', 'list<null>', 'list<true>', 'list<integer>', 'list<object>', 'list<callable>', 'list<self>', 'list<covariant LeafInput>', 'list<*>', 'list<LeafInput', 'list<string>>', 'list<MissingInput>', 'list<\\DateTime>', 'list<\\Symfony\\Component\\Uid\\AbstractUid>'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('invalidTypes')]
    public function testUnsupportedAndMalformedCollectionTypesFail(string $type): void
    {
        $this->fixture->write('BatchInput', 'final readonly class BatchInput { /** @param '.$type.' $items */ public function __construct(public array $items) {} }');
        $this->assertViolation('contract.collection');
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'missing param' => ['', ''];
        yield 'unknown parameter' => ['/** @param list<string> $other */', ''];
        yield 'typeless parameter' => ['/** @param $items */', ''];
        yield 'reference tag' => ['/** @param list<string> &$items */', ''];
        yield 'variadic tag' => ['/** @param list<string> ...$items */', ''];
        yield 'duplicate param' => ["/**\n * @param list<string> \$items\n * @param list<string> \$items\n */", ''];
        yield 'same-line duplicate' => ['/** @param list<string> $items @param list<int> $items */', ''];
        yield 'duplicate blocks' => ['/** @param list<int> $items */ /** @param list<string> $items */', ''];
        yield 'malformed second tag' => ["/**\n * @param list<string> \$items\n * @param list<\n */", ''];
        yield 'override' => ["/**\n * @param list<string> \$items\n * @phpstan-param list<int> \$items\n */", ''];
        yield 'template' => ["/**\n * @template T\n * @param list<T> \$items\n */", ''];
        yield 'conflicting var' => ['/** @param list<string> $items */', '/** @var list<int> */'];
        yield 'malformed var' => ['/** @param list<string> $items */', '/** @var list< */'];
        yield 'misnamed var' => ['/** @param list<string> $items */', '/** @var list<string> $other */'];
        yield 'duplicate var' => ['/** @param list<string> $items */', "/**\n * @var list<string>\n * @var list<string>\n */"];
        yield 'duplicate var blocks' => ['/** @param list<string> $items */', '/** @var list<int> */ /** @var list<string> */'];
        yield 'override var' => ['/** @param list<string> $items */', '/** @psalm-var list<int> */'];
    }

    #[DataProvider('invalidDeclarations')]
    public function testTagsCannotBeMissingDuplicatedMalformedOrOverrideTheContract(string $doc, string $propertyDoc): void
    {
        $this->fixture->write('BatchInput', 'final readonly class BatchInput { '.$doc.' public function __construct('.$propertyDoc.' public array $items) {} }');
        $this->assertViolation('contract.collection_doc');
    }

    public function testClassAliasesCannotOverrideARealNamedDto(): void
    {
        $this->fixture->write('BatchInput', '/** @phpstan-type LeafInput string */ final readonly class BatchInput { /** @param list<LeafInput> $items */ public function __construct(public array $items) {} }');
        $this->assertViolation('contract.collection_doc');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDefaults(): iterable
    {
        foreach (['null', '["value"]', '[1 => "value"]', 'SOME_CONSTANT', 'new \\ArrayObject()'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('invalidDefaults')]
    public function testOnlyEmptyArrayDefaultsAreAllowed(string $default): void
    {
        $this->fixture->write('BatchInput', 'final readonly class BatchInput { /** @param list<string> $items */ public function __construct(public array $items = '.$default.') {} }');
        $this->assertViolation('contract.default');
    }

    public function testNativeNullableAndUnionArraysRemainForbidden(): void
    {
        foreach (['?array', 'array|string'] as $type) {
            $this->fixture->write('BatchInput', 'final readonly class BatchInput { /** @param list<string> $items */ public function __construct(public '.$type.' $items) {} }');
            $this->assertViolation('contract.type');
        }
    }

    public function testDocOnlyForeignInternalsAndEventsCannotBypassModuleBoundaries(): void
    {
        $foreign = 'App\\Module\\GrantProviding\\Domain\\Grant';
        $this->fixture->writeClass($foreign, 'final readonly class Grant {}');
        foreach (['\\'.$foreign, 'Foreign', 'Hidden\\Grant'] as $type) {
            $this->fixture->write('BatchInput', 'use '.$foreign.' as Foreign; use App\\Module\\GrantProviding\\Domain as Hidden; final readonly class BatchInput { /** @param list<'.$type.'> $items */ public function __construct(public array $items) {} }');
            $this->assertViolation('contract.collection_type');
        }
        $this->fixture->write('ObservedEvent', 'final readonly class ObservedEvent extends \\App\\Platform\\Event\\ApplicationEvent {}');
        $this->fixture->write('BatchInput', 'final readonly class BatchInput { /** @param list<ObservedEvent> $items */ public function __construct(public array $items) {} }');
        $this->assertViolation('contract.collection_type');
        $this->fixture->write('BatchInput', 'final readonly class BatchInput {}');
        foreach (['Domain', 'Application', 'Infrastructure'] as $category) {
            $class = 'App\\Module\\'.$this->fixture->module.'\\'.$category.'\\'.('Application' === $category ? 'Inspect' : 'Event').'\\CollectedEvent';
            $this->fixture->writeClass($class, 'final readonly class CollectedEvent extends \\App\\Platform\\Event\\'.$category.'Event { /** @param list<string> $items */ public function __construct(public array $items) {} }');
            $this->assertViolation('contract.collection_type');
        }
    }

    public function testForeignPublicInputsAreAllowedButInterfacesAreNotConcreteItems(): void
    {
        $foreign = 'App\\Module\\GrantProviding\\Application\\Grant\\GrantInput';
        $this->fixture->writeClass($foreign, 'final readonly class GrantInput {}');
        $this->fixture->write('BatchInput', 'final readonly class BatchInput { /** @param list<\\'.$foreign.'> $items */ public function __construct(public array $items) {} }');
        self::assertSame([], (new SourceRules())->violations($this->fixture->root));
        $this->fixture->writeClass($foreign, 'interface GrantInput {}');
        $this->assertViolation('contract.collection_type');
    }

    /** @return iterable<string, array{string, string}> */
    public static function recursiveGraphs(): iterable
    {
        yield 'direct collection' => ['/** @param list<BatchInput> $items */ public function __construct(public array $items) {}', ''];
        yield 'collection through named child' => ['/** @param list<LeafInput> $items */ public function __construct(public array $items) {}', 'public function __construct(public ?BatchInput $parent) {}'];
        yield 'ordinary recursive wrapper with list' => ['/** @param list<string> $items */ public function __construct(public array $items, public ?LeafInput $child) {}', 'public function __construct(public ?BatchInput $parent) {}'];
        yield 'recursive ancestor of collection' => ['public function __construct(public LeafInput $child, public ?BatchInput $parent) {}', '/** @param list<string> $items */ public function __construct(public array $items) {}'];
    }

    #[DataProvider('recursiveGraphs')]
    public function testRecursiveGraphsReachableFromCollectionPathsFail(string $batch, string $leaf): void
    {
        $this->fixture->write('BatchInput', 'final readonly class BatchInput { '.$batch.' }');
        $this->fixture->write('LeafInput', 'final readonly class LeafInput { '.$leaf.' }');
        $this->assertViolation('contract.collection_cycle');
    }

    public function testUnrelatedLegacyScalarDtoCyclesDoNotBecomeCollectionContracts(): void
    {
        $this->fixture->write('LeafInput', 'final readonly class LeafInput { public function __construct(public ?LeafInput $parent) {} }');
        $this->fixture->write('BatchInput', 'final readonly class BatchInput { /** @param list<string> $items */ public function __construct(public array $items) {} }');
        self::assertSame([], (new SourceRules())->violations($this->fixture->root));
    }

    public function testInputRoleIsPublicDataAtTheExactDepthAndNeverAMessage(): void
    {
        $class = $this->fixture->name('LeafInput');
        self::assertTrue(ContractTypes::isPublic($class));
        self::assertTrue(ContractTypes::isDataCandidate($class));
        self::assertNull(ContractTypes::messageKind($class));
        foreach ([str_replace('Inspect\\', '', $class), str_replace('Inspect\\', 'Inspect\\Nested\\', $class)] as $misplaced) {
            $this->fixture->writeClass($misplaced, 'final readonly class LeafInput {}');
            $this->assertViolation('contract.path');
        }
    }

    private function assertViolation(string $rule): void
    {
        $errors = (new SourceRules())->violations($this->fixture->root);
        self::assertNotEmpty($errors);
        self::assertStringContainsString($rule, implode("\n", $errors));
    }
}
