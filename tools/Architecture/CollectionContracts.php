<?php

declare(strict_types=1);

namespace App\Tools\Architecture;

use App\Platform\Architecture\ContractTypes;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Source-only descriptors shared by source policy and the loaded metadata audit.
 * No runtime service consumes this object or the PHPDoc parser.
 *
 * @phpstan-type Lists array<string, array<string, array{type: string, dto: bool}>>
 * @phpstan-type Cascades array<string, list<string>>
 */
final readonly class CollectionContracts
{
    /**
     * @param Lists        $lists
     * @param Cascades     $cascades
     * @param list<string> $violations
     */
    public function __construct(public array $lists, public array $cascades, public array $violations)
    {
    }

    /**
     * @param array<string, array{Stmt\ClassLike, string}> $classes
     * @param array<string, array<string, string>>         $imports
     */
    public static function fromSource(array $classes, array $imports): self
    {
        $docs = new CollectionDocTypes();
        $lists = $edges = $errors = [];
        foreach ($classes as $class => [$node, $path]) {
            if (!ContractTypes::isPublic($class) && !ContractTypes::isAnyEventData($class)) {
                continue;
            }
            try {
                $properties = $docs->properties($class, $node, $imports[$class] ?? []);
            } catch (\LogicException $error) {
                $errors[] = 'contract.collection_doc: '.$path.':'.$node->getStartLine().' '.$class.': '.$error->getMessage();
                $properties = [];
            }
            foreach ($properties as $property => $type) {
                $target = $classes[$type][0] ?? null;
                $dto = ContractTypes::isApplicationData($type) && $target instanceof Stmt\Class_
                    && $target->isFinal() && $target->isReadonly() && null === $target->extends && [] === $target->implements;
                $enum = ContractTypes::isApplicationData($type) && $target instanceof Stmt\Enum_
                    && null !== $target->scalarType && in_array($target->scalarType->name, ['string', 'int'], true);
                if (!ContractTypes::isApplicationData($class)
                    || !(in_array($type, ['string', 'int', 'float', 'bool'], true) || ContractTypes::isImmutable($type) || $dto || $enum)) {
                    $errors[] = 'contract.collection_type: '.$path.':'.$node->getStartLine().' '.$class.'::$'.$property.': unsupported collection item '.$type.'; only scalars, approved immutable values and concrete public Application data are allowed; events are collection-free';
                    continue;
                }
                $lists[$class][$property] = ['type' => $type, 'dto' => $dto];
                if ($dto) {
                    $edges[$class][$property][] = $type;
                }
            }
            if (!ContractTypes::isApplicationData($class)) {
                continue;
            }
            foreach ($node->getMethod('__construct')->params ?? [] as $parameter) {
                if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name) || null === $parameter->type) {
                    continue;
                }
                foreach ((new NodeFinder())->findInstanceOf([$parameter->type], Node\Name::class) as $name) {
                    $type = $name->toString();
                    if (ContractTypes::isApplicationData($type) && ($classes[$type][0] ?? null) instanceof Stmt\Class_) {
                        $edges[$class][$parameter->var->name][] = $type;
                    }
                }
            }
        }

        // Reverse reachability identifies every non-list wrapper that must cascade.
        $affected = array_fill_keys(array_keys($lists), true);
        do {
            $changed = false;
            foreach ($edges as $source => $properties) {
                foreach ($properties as $targets) {
                    foreach ($targets as $target) {
                        if (isset($affected[$target]) && !isset($affected[$source])) {
                            $affected[$source] = true;
                            $changed = true;
                        }
                    }
                }
            }
        } while ($changed);
        $cascades = [];
        foreach ($edges as $source => $properties) {
            foreach ($properties as $property => $targets) {
                if (isset($lists[$source][$property]) || [] !== array_intersect($targets, array_keys($affected))) {
                    $cascades[$source][] = $property;
                }
            }
        }
        // Visit both collection items and ordinary DTO fields. A collection-bearing
        // path cannot hide recursion in a scalar DTO wrapper or a sibling branch.
        $state = [];
        /** @var \Closure(string): void $visit */
        $visit = static function (string $class) use (&$visit, &$state, &$errors, $edges, $classes): void {
            if (1 === ($state[$class] ?? 0)) {
                $errors[] = 'contract.collection_cycle: '.$classes[$class][1].' '.$class.': recursive DTO graph reachable through a collection-bearing path';

                return;
            }
            if (2 === ($state[$class] ?? 0)) {
                return;
            }
            $state[$class] = 1;
            foreach ($edges[$class] ?? [] as $targets) {
                foreach ($targets as $target) {
                    $visit($target);
                }
            }
            $state[$class] = 2;
        };
        foreach (array_keys($affected) as $class) {
            $visit($class);
        }

        return new self($lists, $cascades, $errors);
    }
}
