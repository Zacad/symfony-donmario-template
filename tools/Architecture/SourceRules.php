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
    private ?CollectionContracts $collections = null;

    /** Available after violations(); descriptors are usable only when source passes. */
    public function collections(): CollectionContracts
    {
        return $this->collections ?? throw new \LogicException('Run source analysis before reading collection descriptors.');
    }

    /** @return list<string> Diagnostics contain the rule, file, line and offending declaration. */
    public function violations(string $projectDir): array
    {
        $this->collections = null;
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
        $imports = [];
        foreach ((new Finder())->files()->in($projectDir.'/src')->name('*.php')->sortByName() as $file) {
            $path = 'src/'.$file->getRelativePathname();
            try {
                $nodes = (new NodeTraverser(new NameResolver()))->traverse($parser->parse($file->getContents()) ?? []);
                $imports = [...$imports, ...CollectionDocTypes::imports($nodes)];
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
                if (ContractTypes::isOwnEventListener($class)) {
                    $errors = [...$errors, ...$this->listenerDependencyViolations($class, $declaration, $path, $nodes)];
                }
                if (null !== ModuleMap::owner($class)) {
                    $errors = [...$errors, ...$this->authorizationDependencyViolations($class, $declaration, $path, $nodes)];
                }
                $owner = ModuleMap::owner($class);
                if (!$this->hasOwnedLayout($class) || (null !== $owner && !\in_array($owner, $modules, true))) {
                    $errors[] = $this->diagnostic('source.layout', $path, $declaration, $class, 'expected App\\Module\\<ResponsibilityEndingInIng> in an approved module directory, App\\Platform, or App\\Kernel');
                }
                $expectedPath = 'src/'.str_replace('\\', '/', substr($class, 4)).'.php';
                if (!str_starts_with($class, 'App\\') || $path !== $expectedPath) {
                    $errors[] = $this->diagnostic('source.path', $path, $declaration, $class, 'namespace and filename must match exactly (expected '.$expectedPath.')');
                }
                $pathClass = 'App\\'.str_replace('/', '\\', substr($path, 4, -4));
                if (null !== $owner && str_contains($class, '\\EventListener\\')
                    && !ContractTypes::isOwnEventListener($class) && 1 !== preg_match(ContractTypes::frameworkListenerPattern(), $class)) {
                    $errors[] = $this->diagnostic('event.listener_path', $path, $declaration, $class, 'listeners require Infrastructure/EventListener/<Name>Listener or Infrastructure/Framework/<Library>/EventListener/<Name>');
                }
                if (ContractTypes::isDataCandidate($class) || ContractTypes::isDataCandidate($pathClass)) {
                    if (!ContractTypes::isPublic($class) && !ContractTypes::isAnyEventData($class) && !ContractTypes::isEventPrimitive($class)) {
                        $errors[] = $this->diagnostic('contract.path', $path, $declaration, $class, 'data belongs in Application/<UseCase>/<Name>{Command,Query,Result,Input,Event}, Domain/Event/<Name>Event or Infrastructure/Event/<Name>Event; Contract layouts are forbidden');
                    }
                }
            }
        }
        if ([] === $classes) {
            $errors[] = 'source.coverage: no named first-party declarations were analysed.';
        }
        foreach (ContractTypes::eventPrimitives() as $primitive) {
            if (!isset($classes[$primitive])) {
                $errors[] = 'event.primitive: '.$primitive.' is a required first-party event primitive.';
            }
        }
        $this->collections = CollectionContracts::fromSource($classes, $imports);
        $errors = [...$errors, ...$this->collections->violations];
        foreach ($classes as $class => [$declaration, $path]) {
            if (ContractTypes::isEventPrimitive($class)) {
                $parent = 'App\\Platform\\Event\\BaseEvent' === $class ? null : 'App\\Platform\\Event\\BaseEvent';
                if (!$declaration instanceof Stmt\Class_ || !$declaration->isAbstract() || !$declaration->isReadonly()
                    || $declaration->isFinal() || $parent !== $declaration->extends?->toString()
                    || [] !== $declaration->implements || [] !== $declaration->stmts || [] !== $declaration->attrGroups) {
                    $errors[] = $this->diagnostic('event.primitive', $path, $declaration, $class, 'event primitives must be empty abstract readonly classes with only the exact BaseEvent parent for categories');
                }
            } elseif (ContractTypes::isPublic($class) || ContractTypes::isAnyEventData($class)) {
                $errors = [...$errors, ...$this->contractViolations($class, $declaration, $path, $classes)];
            } elseif ($this->hasEventAncestor($declaration, $classes)) {
                $errors[] = $this->diagnostic('contract.path', $path, $declaration, $class, 'event subclasses require an exact category location and direct primitive parent; intermediate bases are forbidden');
            }
        }
        sort($errors);

        return array_values(array_unique($errors));
    }

    private function hasOwnedLayout(string $class): bool
    {
        $part = '[A-Z][A-Za-z0-9]*';

        return 'App\\Kernel' === $class
            || 1 === preg_match('~^App\\\\Platform\\\\Messaging\\\\Resources\\\\migrations\\\\Version[0-9]{14}$~D', $class)
            || 1 === preg_match('~^App\\\\Platform(?:\\\\'.$part.')+$~D', $class)
            || 1 === preg_match('~^App\\\\Module\\\\'.$part.'ing\\\\(?:'
                .'(?:Application|Domain|Infrastructure)(?:\\\\'.$part.')+'
                .'|UI\\\\(?:Http|Api|Console)(?:\\\\'.$part.')+'
                .'|Resources\\\\migrations\\\\'.$part.')$~D', $class);
    }

    /**
     * Source class constants are declarations, not DI instances. Only a policy
     * constant inside the handler's exact AuthorizeWith attribute gets that exception.
     * Like the listener guard, this checks resolved syntax, not dynamic PHP execution.
     *
     * @param array<Node> $nodes
     *
     * @return list<string>
     */
    private function authorizationDependencyViolations(string $class, Stmt\ClassLike $declaration, string $path, array $nodes): array
    {
        $errors = [];
        $policy = ContractTypes::isPolicy($class);
        $handler = ContractTypes::isApplicationHandler($class);
        $authorizeWith = 'App\\Platform\\Authorization\\AuthorizeWith';
        $policyDeclarations = [];
        $attributes = [];
        foreach ($declaration->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($handler && $authorizeWith === $attribute->name->toString()) {
                    $attributes[] = $attribute;
                    foreach ($attribute->args as $argument) {
                        $constant = $argument->value;
                        if ($constant instanceof Expr\ClassConstFetch && $constant->class instanceof Node\Name && $constant->name instanceof Node\Identifier && 'class' === $constant->name->name
                            && ContractTypes::isPolicy($constant->class->toString())
                            && substr($class, 0, (int) strrpos($class, '\\')) === substr($constant->class->toString(), 0, (int) strrpos($constant->class->toString(), '\\'))) {
                            $policyDeclarations[$constant->class->toString()][] = $constant;
                        }
                    }
                }
            }
        }
        if ('Application' === ModuleMap::layer($class) && str_ends_with($class, 'Policy') && !$policy) {
            $errors[] = $this->diagnostic('authorization.policy_path', $path, $declaration, $class, 'policies require Application/<UseCase>/<Name>Policy');
        }
        foreach ($this->classReferences($declaration, $nodes) as [$reference, $site]) {
            $target = $reference->toString();
            $import = $site instanceof Stmt\Use_ || $site instanceof Stmt\GroupUse;
            if (1 === preg_match(ContractTypes::handlerPattern().'i', $target)) {
                $errors[] = $this->diagnostic('source.handler_dependency', $path, $reference, $class, 'must invoke application handlers through CommandBus/QueryBus, not depend on '.$target);
            }
            if (1 === preg_match(ContractTypes::policyPattern().'i', $target)
                && !($handler && isset($policyDeclarations[$target]) && ($import || in_array($site, $policyDeclarations[$target], true)))) {
                $errors[] = $this->diagnostic('authorization.policy_reference', $path, $reference, $class, 'policy '.$target.' is only a handler AuthorizeWith declaration, never an injected/called service');
            }
            if ($policy && !$this->isPolicyDependency($class, $target)) {
                $errors[] = $this->diagnostic('authorization.policy_dependency', $path, $reference, $class, 'policies may use only public data, QueryBus, Actor/ActorKind/PolicyContext, owning Domain ports/state and approved immutable values/exceptions; found '.$target);
            }
            if (0 === strcasecmp($target, $authorizeWith)
                && !($handler && ($import || in_array($site, $attributes, true)))) {
                $errors[] = $this->diagnostic('authorization.declaration', $path, $reference, $class, 'AuthorizeWith is only a handler class attribute');
            }
            if (1 === preg_match('~^App\\\\Platform\\\\Authorization\\\\(?:Actor|ActorKind|PolicyContext)$~Di', $target) && !$policy) {
                $errors[] = $this->diagnostic('authorization.context', $path, $reference, $class, 'Actor/ActorKind/PolicyContext belong only in policies, never message input or other module services');
            }
            if (0 === strcasecmp($target, 'App\\Platform\\Authorization\\AuthorizationDenied')
                && !('UI' === ModuleMap::layer($class) && ($import || $site instanceof Stmt\Catch_))) {
                $errors[] = $this->diagnostic('authorization.denied', $path, $reference, $class, 'UI may catch AuthorizationDenied; admission belongs to policies');
            }
            if ((str_starts_with(strtolower($target), 'app\\platform\\authorization\\') && !ContractTypes::isAuthorizationData($target)
                || 0 === strcasecmp($target, 'App\\Platform\\Messaging\\InvocationContext'))
                && !ContractTypes::mayUseExecutionFacade($class, $target)) {
                $errors[] = $this->diagnostic('authorization.runtime', $path, $reference, $class, 'execution authority is private; '.$target.' is not an approved facade for this adapter');
            }
        }

        return $errors;
    }

    private function isPolicyDependency(string $source, string $target): bool
    {
        return in_array(strtolower($target), ['self', 'static', 'parent'], true)
            || ContractTypes::isPublic($target) || ContractTypes::isImmutable($target)
            || 1 === preg_match(ContractTypes::POLICY_EXCEPTION_PATTERN, $target)
            || in_array($target, ['App\\Platform\\Messaging\\QueryBus', 'App\\Platform\\Authorization\\Actor', 'App\\Platform\\Authorization\\ActorKind', 'App\\Platform\\Authorization\\PolicyContext'], true)
            || (ModuleMap::owner($source) === ModuleMap::owner($target) && 'Domain' === ModuleMap::layer($target) && !ContractTypes::isAnyEventData($target));
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<array{Node\Name, Node}>
     */
    private function classReferences(Stmt\ClassLike $declaration, array $nodes): array
    {
        $finder = new NodeFinder();
        $references = [];
        foreach ($finder->find($nodes, static fn (Node $node): bool => $node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) as $use) {
            if (!$use instanceof Stmt\Use_ && !$use instanceof Stmt\GroupUse) {
                continue;
            }
            foreach ($use->uses as $item) {
                if (Stmt\Use_::TYPE_NORMAL === ($item->type ?: $use->type)) {
                    $prefix = $use instanceof Stmt\GroupUse ? $use->prefix->toString().'\\' : '';
                    $references[] = [new Node\Name\FullyQualified($prefix.$item->name->toString(), $item->name->getAttributes()), $use];
                }
            }
        }
        foreach ($finder->findInstanceOf([$declaration], Node::class) as $node) {
            $types = match (true) {
                $node instanceof Stmt\Class_ => [$node->extends, ...$node->implements],
                $node instanceof Stmt\Interface_ => $node->extends,
                $node instanceof Stmt\Enum_ => $node->implements,
                $node instanceof Stmt\TraitUse => $node->traits,
                $node instanceof Node\Attribute => [$node->name],
                $node instanceof Expr\New_, $node instanceof Expr\StaticCall,
                $node instanceof Expr\StaticPropertyFetch, $node instanceof Expr\ClassConstFetch,
                $node instanceof Expr\Instanceof_ => [$node->class],
                $node instanceof Node\Param, $node instanceof Stmt\Property,
                $node instanceof Stmt\ClassConst => [$node->type],
                $node instanceof Node\FunctionLike => [$node->getReturnType()],
                $node instanceof Stmt\Catch_ => $node->types,
                default => [],
            };
            foreach ($finder->findInstanceOf(array_filter($types), Node\Name::class) as $reference) {
                $references[] = [$reference, $node];
            }
        }

        return $references;
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<string>
     */
    private function listenerDependencyViolations(string $class, Stmt\ClassLike $declaration, string $path, array $nodes): array
    {
        $errors = [];
        $finder = new NodeFinder();
        $imports = [];
        foreach ($finder->find($nodes, static fn (Node $node): bool => $node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) as $use) {
            if (!$use instanceof Stmt\Use_ && !$use instanceof Stmt\GroupUse) {
                continue;
            }
            foreach ($use->uses as $item) {
                if (Stmt\Use_::TYPE_NORMAL === ($item->type ?: $use->type)) {
                    $prefix = $use instanceof Stmt\GroupUse ? $use->prefix->toString().'\\' : '';
                    $imports[] = new Node\Name\FullyQualified($prefix.$item->name->toString(), $item->name->getAttributes());
                }
            }
        }
        // Deptrac permits edges within a module's EventListener layer. Inspect
        // resolved class-name syntax so a listener cannot invoke another listener
        // directly and bypass Messenger's subscription/priority rules. This does
        // not interpret strings, dynamic lookups or arbitrary callable bodies.
        foreach ($finder->findInstanceOf([$declaration, ...$imports], Node::class) as $node) {
            $references = match (true) {
                $node instanceof Node\Name\FullyQualified && in_array($node, $imports, true) => [$node],
                $node instanceof Stmt\Class_ => [$node->extends, ...$node->implements],
                $node instanceof Stmt\Interface_ => $node->extends,
                $node instanceof Stmt\Enum_ => $node->implements,
                $node instanceof Stmt\TraitUse => $node->traits,
                $node instanceof Node\Attribute => [$node->name],
                $node instanceof Expr\New_, $node instanceof Expr\StaticCall,
                $node instanceof Expr\StaticPropertyFetch, $node instanceof Expr\ClassConstFetch,
                $node instanceof Expr\Instanceof_ => [$node->class],
                $node instanceof Node\Param, $node instanceof Stmt\Property,
                $node instanceof Stmt\ClassConst => [$node->type],
                $node instanceof Node\FunctionLike => [$node->getReturnType()],
                $node instanceof Stmt\Catch_ => $node->types,
                default => [],
            };
            foreach ($finder->findInstanceOf(array_filter($references), Node\Name::class) as $reference) {
                $target = $reference->toString();
                if (0 !== strcasecmp($class, $target) && 1 === preg_match(ContractTypes::listenerPattern().'i', $target)) {
                    $errors[] = $this->diagnostic('event.listener_dependency', $path, $reference, $class, 'must not depend on listener '.$target.'; invoke use cases through CommandBus/QueryBus and let Messenger deliver events');
                }
            }
        }

        return $errors;
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
        if ($node instanceof Stmt\Enum_ && !ContractTypes::isAnyEventData($class)) {
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
        if (!$node->isFinal() || !$node->isReadonly() || ContractTypes::eventCategory($class) !== $node->extends?->toString() || [] !== $node->implements) {
            $errors[] = $this->diagnostic('contract.shape', $path, $node, $class, 'DTO must be final readonly without interfaces; only events extend their exact direct category primitive');
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
                $collection = $parameter->type instanceof Node\Identifier && 'array' === $parameter->type->name
                    && $parameter->var instanceof Expr\Variable && is_string($parameter->var->name)
                    && isset($this->collections()->lists[$class][$parameter->var->name]);
                if (!$collection && !$this->isDataType($parameter->type, $classes, $class)) {
                    $errors[] = $this->diagnostic('contract.type', $path, $parameter, $class, 'expected scalar, declared public data, DateTimeImmutable, Symfony\\Component\\Uid\\Uuid or an approved Application list<T>; events remain collection-free and behavior-bearing types are forbidden');
                }
                if (null !== $parameter->default && ($collection
                    ? (!$parameter->default instanceof Expr\Array_ || [] !== $parameter->default->items)
                    : !$this->isLiteral($parameter->default))) {
                    $errors[] = $this->diagnostic('contract.default', $path, $parameter, $class, 'defaults must be scalar/null literals or [] for an approved list, without calls, construction or constant dependencies');
                }
            }
        }

        return $errors;
    }

    /** @param array<string, array{Stmt\ClassLike, string}> $classes */
    private function isDataType(?Node $type, array $classes, string $source): bool
    {
        if ($type instanceof Node\NullableType) {
            return $this->isDataType($type->type, $classes, $source);
        }
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $member) {
                if (!$this->isDataType($member, $classes, $source)) {
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

            if (ContractTypes::isImmutable($name)) {
                return true;
            }
            // Internal events carry minimal scalar/immutable snapshots, never entities,
            // services, public Application data or abstract category-typed payloads.
            if (ContractTypes::isAnyEventData($source) && !ContractTypes::isEventData($source)) {
                return false;
            }
            if (!isset($classes[$name]) || !(ContractTypes::isEventData($source) ? ContractTypes::isEventData($name) : ContractTypes::isPublic($name))) {
                return false;
            }
            $target = $classes[$name][0];

            return !ContractTypes::isAnyEventData($name) || ($target instanceof Stmt\Class_ && ContractTypes::eventCategory($name) === $target->extends?->toString());
        }

        return false;
    }

    /** @param array<string, array{Stmt\ClassLike, string}> $classes */
    private function hasEventAncestor(Stmt\ClassLike $node, array $classes): bool
    {
        $seen = [];
        while ($node instanceof Stmt\Class_ && null !== $node->extends) {
            $parent = $node->extends->toString();
            if (ContractTypes::isEventPrimitive($parent)) {
                return true;
            }
            if (isset($seen[$parent]) || !isset($classes[$parent])) {
                return false;
            }
            $seen[$parent] = true;
            $node = $classes[$parent][0];
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
