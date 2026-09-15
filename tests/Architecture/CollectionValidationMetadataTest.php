<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Fixtures\CollectionContracts\CollectionFixture;
use App\Tools\Architecture\CollectionValidationMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Yaml\Yaml;

/** @phpstan-type Mapping array<string, array{properties: array<string, list<array<string, mixed>>>}> */
final class CollectionValidationMetadataTest extends TestCase
{
    private CollectionFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new CollectionFixture();
        $this->fixture->write('LeafInput', 'final readonly class LeafInput { /** @param list<string> $labels */ public function __construct(public array $labels) {} }');
        $this->fixture->write('BatchInput', 'final readonly class BatchInput { /** @param list<LeafInput> $items */ public function __construct(public array $items) {} }');
        $this->fixture->write('WrapperResult', 'final readonly class WrapperResult { public function __construct(public ?BatchInput $batch) {} }');
        $this->fixture->load();
    }

    protected function tearDown(): void
    {
        $this->fixture->close();
    }

    public function testRegisteredNativeYamlCoversListsAndEveryNestedDtoEdge(): void
    {
        $validator = $this->validator($this->mapping());
        self::assertSame([], $this->violations($validator));

        // The same native mappings actually reject malformed nested list contents.
        $leaf = $this->fixture->name('LeafInput');
        $batch = $this->fixture->name('BatchInput');
        $wrapper = $this->fixture->name('WrapperResult');
        $value = new $wrapper(new $batch([new $leaf([null])]));
        $violations = $validator->validate($value);
        self::assertCount(1, $violations);
        $violation = $violations[0];
        self::assertNotNull($violation);
        self::assertSame('batch.items[0].labels[0]', $violation->getPropertyPath());
        self::assertCount(0, $validator->validate(new $wrapper(new $batch([]))));
    }

    public function testYamlFilePresenceDoesNotSubstituteForMappingRegistration(): void
    {
        $this->fixture->mapping(Yaml::dump($this->mapping(), 12));
        $errors = $this->violations(Validation::createValidator());
        self::assertStringContainsString('Type(list)', implode("\n", $errors));
        self::assertStringContainsString('property Valid', implode("\n", $errors));
    }

    public function testMissingChildMappingFailsEvenWhenItsParentMappingIsRegistered(): void
    {
        $mapping = $this->mapping();
        unset($mapping[$this->fixture->name('LeafInput')]);
        $errors = $this->violations($this->validator($mapping));
        self::assertStringContainsString($this->fixture->name('LeafInput').'::$labels', implode("\n", $errors));
    }

    /** @return iterable<string, array{list<array<string, mixed>>, string}> */
    public static function incompleteMappings(): iterable
    {
        $type = ['Type' => 'list'];
        $count = ['Count' => ['max' => 4]];
        $all = ['All' => ['constraints' => [['NotNull' => null], ['Type' => 'string']]]];
        yield 'missing list shape' => [[$count, $all], 'Type(list)'];
        yield 'array is not a list' => [[['Type' => 'array'], $count, $all], 'Type(list)'];
        yield 'list or array is too broad' => [[['Type' => ['type' => ['list', 'array']]], $count, $all], 'Type(list)'];
        yield 'list shape in wrong group' => [[['Type' => ['type' => 'list', 'groups' => ['Other']]], $count, $all], 'Type(list)'];
        yield 'missing upper bound' => [[$type, $all], 'finite'];
        yield 'only a lower bound' => [[$type, ['Count' => ['min' => 1]], $all], 'finite'];
        yield 'bound in wrong group' => [[$type, ['Count' => ['max' => 4, 'groups' => ['Other']]], $all], 'finite'];
        yield 'negative upper bound' => [[$type, ['Count' => ['max' => -1]], $all], 'collection.metadata'];
        yield 'missing item constraints' => [[$type, $count], 'All(NotNull'];
        yield 'missing item null guard' => [[$type, $count, ['All' => ['constraints' => [['Type' => 'string']]]]], 'All(NotNull'];
        yield 'wrong scalar item type' => [[$type, $count, ['All' => ['constraints' => [['NotNull' => null], ['Type' => 'int']]]]], 'All(NotNull'];
        yield 'broad scalar item type' => [[$type, $count, ['All' => ['constraints' => [['NotNull' => null], ['Type' => ['type' => ['string', 'int']]]]]]], 'All(NotNull'];
        yield 'All in wrong group' => [[$type, $count, ['All' => ['groups' => ['Other'], 'constraints' => [['NotNull' => null], ['Type' => 'string']]]]], 'All(NotNull'];
        yield 'nested null guard in wrong group' => [[$type, $count, ['All' => ['groups' => ['Default', 'Other'], 'constraints' => [['NotNull' => ['groups' => ['Other']]], ['Type' => ['type' => 'string', 'groups' => ['Default']]]]]]], 'All(NotNull'];
        yield 'nested type in wrong group' => [[$type, $count, ['All' => ['groups' => ['Default', 'Other'], 'constraints' => [['NotNull' => ['groups' => ['Default']]], ['Type' => ['type' => 'string', 'groups' => ['Other']]]]]]], 'All(NotNull'];
        yield 'conditional structure is not supported proof' => [[['AtLeastOneOf' => ['constraints' => [$type, $count, $all]]]], 'Type(list)'];
    }

    /** @param list<array<string, mixed>> $constraints */
    #[DataProvider('incompleteMappings')]
    public function testIncompleteOrWrongDefaultGroupMappingsFail(array $constraints, string $diagnostic): void
    {
        $mapping = $this->mapping();
        $mapping[$this->fixture->name('LeafInput')]['properties']['labels'] = $constraints;
        $errors = $this->violations($this->validator($mapping));
        self::assertNotEmpty($errors);
        self::assertStringContainsString($diagnostic, implode("\n", $errors));
    }

    /** @return iterable<string, array{string, list<array<string, mixed>>}> */
    public static function missingCascades(): iterable
    {
        yield 'list DTO items need Valid' => ['BatchInput', []];
        yield 'list Valid in wrong group' => ['BatchInput', [['Valid' => ['groups' => ['Other']]]]];
        yield 'wrapper needs Valid' => ['WrapperResult', []];
        yield 'wrapper Valid in wrong group' => ['WrapperResult', [['Valid' => ['groups' => ['Other']]]]];
    }

    /** @param list<array<string, mixed>> $valid */
    #[DataProvider('missingCascades')]
    public function testDtoItemsAndOrdinaryWrappersNeedDefaultPropertyCascades(string $class, array $valid): void
    {
        $mapping = $this->mapping();
        if ('BatchInput' === $class) {
            $mapping[$this->fixture->name($class)]['properties']['items'] = [...$this->listConstraints($this->fixture->name('LeafInput')), ...$valid];
        } else {
            $mapping[$this->fixture->name($class)]['properties']['batch'] = $valid;
        }
        $errors = $this->violations($this->validator($mapping));
        self::assertStringContainsString($this->fixture->name($class).'::$', implode("\n", $errors));
        self::assertStringContainsString('property Valid', implode("\n", $errors));
    }

    public function testExplicitDefaultValidAndZeroUpperBoundAreSupported(): void
    {
        $mapping = $this->mapping();
        $mapping[$this->fixture->name('WrapperResult')]['properties']['batch'] = [['Valid' => ['groups' => ['Default']]]];
        $mapping[$this->fixture->name('LeafInput')]['properties']['labels'] = [
            ['Type' => 'list'], ['Count' => ['max' => 0]],
            ['All' => ['constraints' => [['NotNull' => null], ['Type' => 'string']]]],
        ];
        self::assertSame([], $this->violations($this->validator($mapping)));
    }

    public function testImmutableEnumAndOtherScalarItemsRequireTheirExactDeclaredTypes(): void
    {
        $this->fixture->write('StateInput', 'enum StateInput: int { case Ready = 1; }');
        foreach (['int', 'float', 'bool', '\\Symfony\\Component\\Uid\\Uuid', '\\DateTimeImmutable', 'StateInput'] as $type) {
            // One distinct class per iteration because native metadata loads classes.
            $name = 'Typed'.md5($type).'Result';
            $this->fixture->write($name, 'final readonly class '.$name.' { /** @param list<'.$type.'> $values */ public function __construct(public array $values) {} }');
        }
        $contracts = $this->fixture->contracts();
        $mapping = $this->mapping();
        foreach ($contracts->lists as $class => $properties) {
            if (isset($properties['values'])) {
                $mapping[$class]['properties']['values'] = $this->listConstraints($properties['values']['type']);
            }
        }
        self::assertSame([], $this->violations($this->validator($mapping)));
        $mapping[$this->fixture->name('BatchInput')]['properties']['items'] = [...$this->listConstraints('object'), ['Valid' => null]];
        self::assertStringContainsString('All(NotNull + Type('.$this->fixture->name('LeafInput').'))', implode("\n", $this->violations($this->validator($mapping))));
    }

    public function testMalformedRegisteredMappingFailsWithADeclarationOnlyDiagnostic(): void
    {
        $path = $this->fixture->mapping("[invalid: yaml\n");
        $validator = Validation::createValidatorBuilder()->addYamlMapping($path)->getValidator();
        $errors = $this->violations($validator);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('native validator metadata could not be loaded', implode("\n", $errors));
        self::assertStringNotContainsString('[invalid', implode("\n", $errors));
    }

    public function testGroupSequenceOverridesAndClassCascadeAreNotAcceptedAsPropertyProof(): void
    {
        foreach (['group_sequence: [WrapperResult]', 'constraints: [ { Cascade: ~ } ]'] as $override) {
            $mapping = $this->mapping();
            $yaml = Yaml::dump($mapping, 12);
            $class = $this->fixture->name('WrapperResult');
            $yaml = str_replace($class.":\n", $class.":\n    ".$override."\n", $yaml);
            $validator = Validation::createValidatorBuilder()->addYamlMapping($this->fixture->mapping($yaml))->getValidator();
            self::assertStringContainsString('requires ordinary native Default-group property metadata', implode("\n", $this->violations($validator)));
        }
    }

    /** @return Mapping */
    private function mapping(): array
    {
        return [
            $this->fixture->name('LeafInput') => ['properties' => ['labels' => $this->listConstraints('string')]],
            $this->fixture->name('BatchInput') => ['properties' => ['items' => [...$this->listConstraints($this->fixture->name('LeafInput')), ['Valid' => null]]]],
            $this->fixture->name('WrapperResult') => ['properties' => ['batch' => [['Valid' => null]]]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function listConstraints(string $type): array
    {
        return [['Type' => 'list'], ['Count' => ['max' => 4]], ['All' => ['constraints' => [['NotNull' => null], ['Type' => $type]]]]];
    }

    /** @param Mapping $mapping */
    private function validator(array $mapping): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->addYamlMapping($this->fixture->mapping(Yaml::dump($mapping, 12)))->getValidator();
    }

    /** @return list<string> */
    private function violations(ValidatorInterface $validator): array
    {
        return (new CollectionValidationMetadata())->violations($this->fixture->contracts(), $validator);
    }
}
