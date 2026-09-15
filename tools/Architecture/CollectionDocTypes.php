<?php

declare(strict_types=1);

namespace App\Tools\Architecture;

use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/** Build-time PHPDoc AST interpretation; never reflects or loads application source. */
final class CollectionDocTypes
{
    private Lexer $lexer;
    private PhpDocParser $parser;

    public function __construct()
    {
        $config = new ParserConfig(usedAttributes: []);
        $constants = new ConstExprParser($config);
        $this->lexer = new Lexer($config);
        $this->parser = new PhpDocParser($config, new TypeParser($config, $constants), $constants);
    }

    /**
     * Class imports are lexical, case-insensitive aliases; function/constant imports
     * do not participate. Each namespace block starts a fresh import context.
     *
     * @param array<Node> $nodes
     *
     * @return array<string, array<string, string>>
     */
    public static function imports(array $nodes): array
    {
        $contexts = [];
        $imports = [];
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                $contexts = [...$contexts, ...self::imports($node->stmts)];
            } elseif ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
                foreach ($node->uses as $use) {
                    if (Stmt\Use_::TYPE_NORMAL !== ($use->type ?: $node->type)) {
                        continue;
                    }
                    $prefix = $node instanceof Stmt\GroupUse ? $node->prefix->toString().'\\' : '';
                    $imports[strtolower($use->getAlias()->toString())] = $prefix.$use->name->toString();
                }
            } elseif ($node instanceof Stmt\ClassLike && isset($node->namespacedName)) {
                $contexts[$node->namespacedName->toString()] = $imports;
            }
        }

        return $contexts;
    }

    /**
     * @param array<string, string> $imports
     *
     * @return array<string, string> promoted native array property => resolved item type
     */
    public function properties(string $class, Stmt\ClassLike $node, array $imports): array
    {
        $this->tags($node);
        $constructor = $node->getMethod('__construct');
        if (null === $constructor) {
            return [];
        }
        $parameters = [];
        foreach ($constructor->params as $parameter) {
            if ($parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)) {
                $parameters['$'.$parameter->var->name] = $parameter;
            }
        }
        $docs = [];
        foreach ($this->tags($constructor) as $tag) {
            if ('@param' !== $tag->name) {
                continue;
            }
            $value = $tag->value;
            if (!$value instanceof ParamTagValueNode || $value->isReference || $value->isVariadic
                || !isset($parameters[$value->parameterName]) || isset($docs[$value->parameterName])) {
                throw new \LogicException('constructor @param must name one existing parameter exactly once, without reference/variadic syntax');
            }
            $docs[$value->parameterName] = $value;
        }
        $properties = [];
        foreach ($parameters as $name => $parameter) {
            $vars = array_values(array_filter($this->tags($parameter), static fn (PhpDocTagNode $tag): bool => '@var' === $tag->name));
            if (!$parameter->type instanceof Node\Identifier || 'array' !== $parameter->type->name) {
                continue;
            }
            if (!isset($docs[$name])) {
                throw new \LogicException($name.' requires constructor @param list<T>');
            }
            $item = $this->item($docs[$name]->type, $class, $imports);
            if (count($vars) > 1) {
                throw new \LogicException($name.' has duplicate promoted @var tags');
            }
            foreach ($vars as $var) {
                if (!$var->value instanceof VarTagValueNode
                    || !in_array($var->value->variableName, ['', $name], true)
                    || $item !== $this->item($var->value->type, $class, $imports)) {
                    throw new \LogicException($name.' promoted @var must agree with constructor @param list<T>');
                }
            }
            $properties[substr($name, 1)] = $item;
        }

        return $properties;
    }

    /** @return list<PhpDocTagNode> */
    private function tags(Node $node): array
    {
        if (count(array_filter($node->getComments(), static fn (Comment $comment): bool => $comment instanceof Doc)) > 1) {
            throw new \LogicException('multiple PHPDoc blocks on one declaration are ambiguous');
        }
        $comment = $node->getDocComment();
        if (null === $comment) {
            return [];
        }
        try {
            $tokens = new TokenIterator($this->lexer->tokenize($comment->getText()));
            $doc = $this->parser->parse($tokens);
            $tokens->consumeTokenType(Lexer::TOKEN_END);
        } catch (\Throwable $error) {
            throw new \LogicException('malformed PHPDoc', previous: $error);
        }
        $tags = [];
        foreach ($doc->children as $child) {
            if (!$child instanceof PhpDocTagNode) {
                continue;
            }
            if ($child->value instanceof InvalidTagValueNode) {
                throw new \LogicException('malformed PHPDoc tag '.$child->name);
            }
            if (preg_match('/^@(?:(?:phpstan|psalm|phan)-|template|property|method|mixin|extends|implements|use$|param-out)/D', $child->name)) {
                throw new \LogicException('unsupported PHPDoc alias/template/override '.$child->name);
            }
            // A second tag on the same line is parsed as a description by PHPDoc.
            // Reject it rather than silently accepting a hidden override/duplicate.
            if (property_exists($child->value, 'description') && is_string($child->value->description)
                && preg_match('/(?:^|\s)@[A-Za-z]/', $child->value->description)) {
                throw new \LogicException('PHPDoc tags must be on separate lines');
            }
            $tags[] = $child;
        }

        return $tags;
    }

    /** @param array<string, string> $imports */
    private function item(TypeNode $type, string $class, array $imports): string
    {
        if (!$type instanceof GenericTypeNode || 'list' !== $type->type->name || 1 !== count($type->genericTypes)
            || !$type->genericTypes[0] instanceof IdentifierTypeNode
            || [] !== array_diff($type->variances, [GenericTypeNode::VARIANCE_INVARIANT])) {
            throw new \LogicException('expected canonical homogeneous nonnullable list<T>, with one nonnullable named item type');
        }
        $name = $type->genericTypes[0]->name;
        if (in_array($name, ['string', 'int', 'float', 'bool'], true)) {
            return $name;
        }
        if (1 !== preg_match('/^\\\\?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $name)
            || in_array(strtolower($name), ['self', 'static', 'parent', 'mixed', 'null', 'true', 'false', 'array', 'list', 'iterable', 'object', 'callable', 'resource', 'never', 'void', 'integer', 'boolean', 'double', 'scalar'], true)) {
            throw new \LogicException('unsupported list item type '.$name);
        }
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return $namespace.'\\'.substr($name, 10);
        }
        $parts = explode('\\', $name, 2);
        if (isset($imports[strtolower($parts[0])])) {
            return $imports[strtolower($parts[0])].(isset($parts[1]) ? '\\'.$parts[1] : '');
        }

        return $namespace.'\\'.$name;
    }
}
