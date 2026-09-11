<?php

declare(strict_types=1);

namespace App\Tools\Architecture;

use App\Platform\Architecture\ContractTypes;
use App\Platform\Architecture\ModuleMap;
use PhpParser\Error;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/** Parses first-party source without loading application classes or booting a kernel. */
final class SourceRules
{
    /** @return list<string> Diagnostics contain the rule, file, line and offending declaration. */
    public function violations(string $projectDir): array
    {
        $errors = [];
        $map = new ModuleMap($projectDir);
        try {
            $modules = $map->modules();
        } catch (\LogicException $error) {
            return [$error->getMessage()];
        }
        if ([] === $modules) {
            $errors[] = 'source.coverage: no responsibility-ending-in-ing modules found in src/Module.';
        }
        if (!is_dir($projectDir.'/src')) {
            return [...$errors, 'source.coverage: src does not exist.'];
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        /** @var array<string, array{Stmt\ClassLike, string}> $classes */
        $classes = [];
        foreach ((new Finder())->files()->in($projectDir.'/src')->name('*.php')->sortByName() as $file) {
            $path = 'src/'.$file->getRelativePathname();
            try {
                $nodes = (new NodeTraverser(new NameResolver()))->traverse($parser->parse($file->getContents()) ?? []);
            } catch (Error $error) {
                $errors[] = 'source.parse: '.$path.': '.$error->getMessage();
                continue;
            }
            $declarations = $finder->findInstanceOf($nodes, Stmt\ClassLike::class);
            $resourceConfig = 1 === preg_match('~^src/Module/([A-Z][A-Za-z0-9]*ing)/Resources/config/.+\.php$~D', $path, $matches)
                && \in_array($matches[1], $modules, true);
            if ($resourceConfig) {
                $errors[] = 'source.config: '.$path.' PHP configuration is unsupported; use module-owned YAML with compiled service validation.';
                continue;
            }
            if (1 !== \count($declarations)) {
                $errors[] = 'source.declaration: '.$path.' must contain exactly one named class, interface, trait or enum.';
            }
            foreach ($finder->findInstanceOf($nodes, Stmt\Function_::class) as $function) {
                $errors[] = $this->diagnostic('source.declaration', $path, $function, (string) $function->namespacedName, 'named functions are outside the class-layer graph');
            }
            $errors = [...$errors, ...$this->topLevelViolations($nodes, $path)];
            foreach ($declarations as $declaration) {
                $class = (string) ($declaration->namespacedName ?? null);
                if ('' === $class) {
                    $errors[] = $this->diagnostic('source.declaration', $path, $declaration, '(anonymous)', 'anonymous classes have no owned namespace');
                    continue;
                }
                if (isset($classes[$class])) {
                    $errors[] = $this->diagnostic('source.declaration', $path, $declaration, $class, 'duplicate declaration');
                }
                $classes[$class] = [$declaration, $path];
                $owner = ModuleMap::owner($class);
                if (!$this->hasOwnedLayout($class) || (null !== $owner && !\in_array($owner, $modules, true))) {
                    $errors[] = $this->diagnostic('source.layout', $path, $declaration, $class, 'expected App\\Module\\<ResponsibilityEndingInIng> in an approved module directory, App\\Platform, or App\\Kernel');
                }
                $expectedPath = 'src/'.str_replace('\\', '/', substr($class, 4)).'.php';
                if (!str_starts_with($class, 'App\\') || $path !== $expectedPath) {
                    $errors[] = $this->diagnostic('source.path', $path, $declaration, $class, 'namespace and filename must match exactly (expected '.$expectedPath.')');
                }
                $pathClass = 'App\\'.str_replace('/', '\\', substr($path, 4, -4));
                if (ContractTypes::isDataCandidate($class) || ContractTypes::isDataCandidate($pathClass)) {
                    if (!ContractTypes::isPublic($class)) {
                        $errors[] = $this->diagnostic('contract.path', $path, $declaration, $class, 'public data belongs in Application/<UseCase>/<Name>{Command,Query,Result} or Contract/Event/<Name>');
                    }
                }
            }
        }
        if ([] === $classes) {
            $errors[] = 'source.coverage: no named first-party declarations were analysed.';
        }
        foreach ($classes as $class => [$declaration, $path]) {
            if (ContractTypes::isPublic($class)) {
                $errors = [...$errors, ...$this->contractViolations($class, $declaration, $path, $classes)];
            }
        }
        sort($errors);

        return array_values(array_unique($errors));
    }

    private function hasOwnedLayout(string $class): bool
    {
        $part = '[A-Z][A-Za-z0-9]*';

        return 'App\\Kernel' === $class
            || 1 === preg_match('~^App\\\\Platform(?:\\\\'.$part.')+$~D', $class)
            || 1 === preg_match('~^App\\\\Module\\\\'.$part.'ing\\\\(?:'
                .'(?:Application|Domain|Infrastructure)(?:\\\\'.$part.')+'
                .'|UI\\\\(?:Http|Api|Console|Event)(?:\\\\'.$part.')+'
                .'|Resources\\\\migrations\\\\'.$part
                .'|Contract\\\\Event\\\\'.$part.')$~D', $class);
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<string>
     */
    private function topLevelViolations(array $nodes, string $path): array
    {
        $errors = [];
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                $errors = [...$errors, ...$this->topLevelViolations($node->stmts, $path)];
            } elseif ($node instanceof Stmt\Declare_ && null === $node->stmts) {
                continue;
            } elseif (!$node instanceof Stmt\Use_ && !$node instanceof Stmt\GroupUse && !$node instanceof Stmt\ClassLike && !$node instanceof Stmt\Nop) {
                $errors[] = $this->diagnostic('source.declaration', $path, $node, '(file)', 'executable top-level source is outside the class-layer graph');
            }
        }

        return $errors;
    }

    /**
     * @param array<string, array{Stmt\ClassLike, string}> $classes
     *
     * @return list<string>
     */
    private function contractViolations(string $class, Stmt\ClassLike $node, string $path, array $classes): array
    {
        $errors = [];
        foreach ((new NodeFinder())->findInstanceOf([$node], Node\Attribute::class) as $attribute) {
            $errors[] = $this->diagnostic('contract.attributes', $path, $attribute, $class, 'attributes are behavior/wiring, not contract data');
        }
        if ($node instanceof Stmt\Enum_) {
            if (null === $node->scalarType || !\in_array($node->scalarType->name, ['string', 'int'], true) || [] !== $node->implements) {
                $errors[] = $this->diagnostic('contract.enum', $path, $node, $class, 'expected a string/int backed enum without interfaces');
            }
            $values = [];
            foreach ($node->stmts as $statement) {
                if (!$statement instanceof Stmt\EnumCase) {
                    $errors[] = $this->diagnostic('contract.enum', $path, $statement, $class, 'only literal backed cases are allowed; no methods, constants or traits');
                    continue;
                }
                $literal = $this->enumLiteral($statement->expr);
                if (!(('string' === $node->scalarType?->name && \is_string($literal))
                    || ('int' === $node->scalarType?->name && \is_int($literal)))) {
                    $errors[] = $this->diagnostic('contract.enum', $path, $statement, $class, 'case must be a literal matching the backing type');
                } else {
                    if (\in_array($literal, $values, true)) {
                        $errors[] = $this->diagnostic('contract.enum', $path, $statement, $class, 'duplicate backed case value');
                    }
                    $values[] = $literal;
                }
            }

            return $errors;
        }
        if (!$node instanceof Stmt\Class_) {
            return [...$errors, $this->diagnostic('contract.shape', $path, $node, $class, 'expected a final readonly DTO or backed data enum')];
        }
        if (!$node->isFinal() || !$node->isReadonly() || null !== $node->extends || [] !== $node->implements) {
            $errors[] = $this->diagnostic('contract.shape', $path, $node, $class, 'DTO must be final readonly without inheritance or interfaces');
        }
        foreach ($node->stmts as $statement) {
            if (!$statement instanceof Stmt\ClassMethod || '__construct' !== $statement->name->toString()) {
                $errors[] = $this->diagnostic('contract.behavior', $path, $statement, $class, 'only an empty constructor with promoted data is allowed; no methods, properties, constants or traits');
                continue;
            }
            if (!\in_array($statement->flags, [0, Modifiers::PUBLIC], true) || $statement->byRef || null !== $statement->returnType || [] !== $statement->stmts) {
                $errors[] = $this->diagnostic('contract.constructor', $path, $statement, $class, 'constructor must be public, ordinary and have an empty body');
            }
            foreach ($statement->params as $parameter) {
                if (!\in_array($parameter->flags, [Modifiers::PUBLIC, Modifiers::PUBLIC | Modifiers::READONLY], true)
                    || $parameter->byRef || $parameter->variadic || [] !== $parameter->hooks) {
                    $errors[] = $this->diagnostic('contract.property', $path, $parameter, $class, 'parameters must be promoted public data properties without references, variadics or hooks');
                }
                if (!$this->isDataType($parameter->type, $classes, ContractTypes::isEventData($class))) {
                    $errors[] = $this->diagnostic('contract.type', $path, $parameter, $class, 'expected scalar, declared public data, DateTimeImmutable or Symfony\\Component\\Uid\\Uuid; events may reference only public event data; collections and behavior-bearing types are forbidden');
                }
                if (null !== $parameter->default && !$this->isLiteral($parameter->default)) {
                    $errors[] = $this->diagnostic('contract.default', $path, $parameter, $class, 'defaults must be scalar/null literals, without calls, construction or constant dependencies');
                }
            }
        }

        return $errors;
    }

    /** @param array<string, array{Stmt\ClassLike, string}> $classes */
    private function isDataType(?Node $type, array $classes, bool $eventsOnly): bool
    {
        if ($type instanceof Node\NullableType) {
            return $this->isDataType($type->type, $classes, $eventsOnly);
        }
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $member) {
                if (!$this->isDataType($member, $classes, $eventsOnly)) {
                    return false;
                }
            }

            return true;
        }
        if ($type instanceof Node\Identifier) {
            return \in_array($type->name, ['string', 'int', 'float', 'bool', 'null', 'true', 'false'], true);
        }
        if ($type instanceof Node\Name) {
            $name = $type->toString();

            return ContractTypes::isImmutable($name) || (($eventsOnly ? ContractTypes::isEventData($name) : ContractTypes::isPublic($name)) && isset($classes[$name]));
        }

        return false;
    }

    private function isLiteral(Expr $expression): bool
    {
        if ($expression instanceof Scalar\String_ || $expression instanceof Scalar\Int_ || $expression instanceof Scalar\Float_) {
            return true;
        }
        if ($expression instanceof Expr\UnaryMinus || $expression instanceof Expr\UnaryPlus) {
            return $expression->expr instanceof Scalar\Int_ || $expression->expr instanceof Scalar\Float_;
        }

        return $expression instanceof Expr\ConstFetch && \in_array(strtolower($expression->name->toString()), ['null', 'true', 'false'], true);
    }

    private function enumLiteral(?Expr $expression): string|int|null
    {
        if ($expression instanceof Scalar\String_ || $expression instanceof Scalar\Int_) {
            return $expression->value;
        }
        if ($expression instanceof Expr\UnaryMinus && $expression->expr instanceof Scalar\Int_) {
            return -$expression->expr->value;
        }
        if ($expression instanceof Expr\UnaryPlus && $expression->expr instanceof Scalar\Int_) {
            return $expression->expr->value;
        }

        return null;
    }

    private function diagnostic(string $rule, string $path, Node $node, string $class, string $message): string
    {
        return $rule.': '.$path.':'.$node->getStartLine().' '.$class.': '.$message;
    }
}
