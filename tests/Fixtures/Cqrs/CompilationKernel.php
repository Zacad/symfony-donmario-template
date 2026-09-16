<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Cqrs;

use App\Kernel;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskHandler;
use App\Platform\Authorization\Actor;
use App\Platform\Authorization\AuthorizationMiddleware;
use App\Platform\Authorization\ExecutionContext;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\CommandTransactionMiddleware;
use App\Platform\Messaging\InvocationContext;
use App\Platform\Messaging\QueryBus;
use App\Platform\Messaging\ResultValidationMiddleware;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;

final class CompilationKernel extends Kernel
{
    private readonly string $directory;

    public function __construct(private readonly string $scenario, string $environment)
    {
        parent::__construct($environment, false);
        $this->directory = sys_get_temp_dir().'/cqrs-compilation-'.bin2hex(random_bytes(12));
    }

    /** @param callable(ContainerBuilder): void $test */
    public static function run(string $scenario, callable $test): void
    {
        $kernel = new self($scenario, 'test');
        try {
            $kernel->initializeBundles();
            $test($kernel->buildContainer());
        } finally {
            $kernel->shutdown();
            new Filesystem()->remove($kernel->directory);
        }
    }

    public function cleanup(): void
    {
        $this->shutdown();
        new Filesystem()->remove($this->directory);
    }

    /** @param callable(ContainerInterface): void $test */
    public static function withBooted(string $scenario, callable $test): void
    {
        $kernel = new self($scenario, 'test');
        try {
            $kernel->boot();
            $test($kernel->getContainer());
        } finally {
            $kernel->cleanup();
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $type = in_array($this->scenario, ['locator-constructor', 'descriptor-constructor'], true) ? PassConfig::TYPE_BEFORE_REMOVING : PassConfig::TYPE_BEFORE_OPTIMIZATION;
        $container->addCompilerPass(new AlterCqrsPass($this->scenario), $type, 20);
        $container->addCompilerPass(new class($this->scenario) implements CompilerPassInterface {
            public function __construct(private readonly string $scenario)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                CompilationKernel::authorizationFixture($container, $this->scenario);
                switch ($this->scenario) {
                    case 'authorization-context':
                        $container->register('test.other.execution', ExecutionContext::class);
                        $container->getDefinition(AuthorizationMiddleware::class)->setArgument(1, new Reference('test.other.execution'));
                        break;
                    case 'authorization-data-service':
                        $container->register('test.actor', Actor::class);
                        break;
                    case 'authorization-locator-factory':
                    case 'authorization-locator-map':
                    case 'authorization-locator-public':
                    case 'authorization-locator-alias':
                        $reference = $container->getDefinition(AuthorizationMiddleware::class)->getArgument(2);
                        if (!$reference instanceof Reference) {
                            throw new \LogicException('Expected compiled policy locator reference.');
                        }
                        $locator = $container->findDefinition((string) $reference);
                        if ('authorization-locator-factory' === $this->scenario) {
                            $locator->setFactory('test_factory');
                        } elseif ('authorization-locator-public' === $this->scenario) {
                            $locator->setPublic(true);
                        } elseif ('authorization-locator-alias' === $this->scenario) {
                            $container->setAlias('test.policy.locator', (string) $reference)->setPublic(true);
                        } else {
                            $locator->setArgument(0, ['unexpected' => new Reference(CreateTaskHandler::class)]);
                        }
                        break;
                    case 'authorization-missing-command':
                    case 'authorization-missing-query':
                    case 'authorization-after-result':
                        $bus = 'authorization-missing-query' === $this->scenario ? 'query' : 'command';
                        $definition = $container->getDefinition($bus.'.bus');
                        $argument = $definition->getArgument(0);
                        if (!$argument instanceof IteratorArgument) {
                            throw new \LogicException('Expected compiled middleware iterator.');
                        }
                        $values = array_values(array_filter($argument->getValues(), static fn (mixed $value): bool => !$value instanceof Reference || AuthorizationMiddleware::class !== (string) $value));
                        if ('authorization-after-result' === $this->scenario) {
                            array_splice($values, count($values) - 1, 0, [new Reference(AuthorizationMiddleware::class)]);
                        }
                        $definition->setArgument(0, new IteratorArgument($values));
                        break;
                    case 'result-validator':
                        $container->register('test.other.validator', \Symfony\Component\Validator\Validator\RecursiveValidator::class);
                        $container->getDefinition(ResultValidationMiddleware::class)->setArgument(0, new Reference('test.other.validator'));
                        break;
                    case 'transaction-context':
                        $container->register('test.other.context', InvocationContext::class);
                        $container->getDefinition(CommandTransactionMiddleware::class)->setArgument(1, new Reference('test.other.context'));
                        break;
                    case 'manager-connection':
                        $container->register('test.other.connection', \Doctrine\DBAL\Connection::class);
                        $container->getDefinition('doctrine.orm.default_entity_manager')->setArgument(0, new Reference('test.other.connection'));
                        break;
                    case 'default-connection':
                        $container->getDefinition('doctrine')->setArgument(3, 'other');
                        break;
                    case 'connection-autocommit':
                        $container->getDefinition('doctrine.dbal.default_connection.configuration')->addMethodCall('setAutoCommit', [false]);
                        break;
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING, 20);
        $container->setAlias('test.cqrs.validator', 'validator')->setPublic(true);
        $container->setAlias('test.cqrs.commands', CommandBus::class)->setPublic(true);
        $container->setAlias('test.cqrs.queries', QueryBus::class)->setPublic(true);
    }

    /** Generated classes keep synthetic compilation scenarios explicit without changing production policies. */
    public static function authorizationFixture(ContainerBuilder $container, string $scenario): void
    {
        $policyScenarios = ['authorization-missing', 'authorization-duplicate', 'authorization-method', 'authorization-policy-missing', 'authorization-policy-mutable', 'authorization-policy-nonfinal', 'authorization-policy-foreign', 'authorization-policy-message', 'authorization-policy-context', 'authorization-policy-return', 'authorization-policy-optional', 'authorization-policy-variadic', 'authorization-policy-reference', 'authorization-policy-public', 'authorization-policy-alias', 'authorization-policy-factory', 'authorization-policy-copy'];
        if (!in_array($scenario, [...$policyScenarios, 'accepted-data-result', 'void-result', 'authorization-valid'], true)) {
            return;
        }
        $namespace = 'App\\Module\\TaskTracking\\Application\\CreateTask';
        $suffix = ucfirst(substr(hash('sha256', $scenario), 0, 12));
        $policyName = 'Compilation'.$suffix.'Policy';
        $handlerName = 'Compilation'.$suffix.'Handler';
        $policyNamespace = 'authorization-policy-foreign' === $scenario ? 'App\\Module\\TaskTracking\\Application\\GetTask' : $namespace;
        $policyClass = $policyNamespace.'\\'.$policyName;
        $handlerClass = $namespace.'\\'.$handlerName;
        $handler = $container->getDefinition(CreateTaskHandler::class);
        if (!class_exists($handlerClass, false)) {
            $modifiers = match ($scenario) {
                'authorization-policy-mutable' => 'final',
                'authorization-policy-nonfinal' => 'readonly',
                default => 'final readonly',
            };
            $messageType = 'authorization-policy-message' === $scenario ? '\\stdClass' : '\\'.CreateTaskCommand::class;
            $contextType = 'authorization-policy-context' === $scenario ? '\\stdClass' : '\\App\\Platform\\Authorization\\PolicyContext';
            $context = match ($scenario) {
                'authorization-policy-optional' => $contextType.' $context = new '.$contextType.'(new \\App\\Platform\\Authorization\\Actor("anonymous"), false, null)',
                'authorization-policy-variadic' => $contextType.' ...$context',
                'authorization-policy-reference' => $contextType.' &$context',
                default => $contextType.' $context',
            };
            $return = 'authorization-policy-return' === $scenario ? '?bool' : 'bool';
            eval('namespace '.$policyNamespace.'; '.$modifiers.' class '.$policyName.' { public function __invoke('.$messageType.' $message, '.$context.'): '.$return.' { return true; } }');
            $attribute = '#[\\App\\Platform\\Authorization\\AuthorizeWith(\\'.$policyClass.'::class)]';
            $declaration = match ($scenario) {
                'authorization-missing', 'authorization-method' => '',
                'authorization-duplicate' => $attribute.$attribute,
                default => $attribute,
            };
            $methodAttribute = 'authorization-method' === $scenario ? $attribute : '';
            $handlerReturn = 'void';
            if (in_array($scenario, ['accepted-data-result', 'void-result'], true)) {
                $reflection = new \ReflectionMethod($handler->getClass() ?? '', '__invoke');
                $type = $reflection->getReturnType();
                $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
                $handlerReturn = implode('|', array_map(static function (?\ReflectionType $member): string {
                    if (!$member instanceof \ReflectionNamedType) {
                        throw new \LogicException('Expected named fixture return type.');
                    }

                    return ($member->isBuiltin() ? '' : '\\').$member->getName();
                }, $types));
            }
            eval('namespace '.$namespace.'; '.$declaration.' final class '.$handlerName.' { '.$methodAttribute.' public function __invoke(\\'.CreateTaskCommand::class.' $message): '.$handlerReturn.' { throw new \\LogicException("Compilation-only fixture."); } }');
        }
        $handler->setClass($handlerClass)->setArguments([]);
        if ('authorization-policy-missing' === $scenario) {
            return;
        }
        $policy = $container->register($policyClass, $policyClass);
        switch ($scenario) {
            case 'authorization-policy-public':
                $policy->setPublic(true);
                break;
            case 'authorization-policy-alias':
                $container->setAlias('test.policy.private.alias', $policyClass);
                $container->setAlias('test.policy.public.alias', 'test.policy.private.alias')->setPublic(true);
                break;
            case 'authorization-policy-factory':
                $policy->setFactory('test_factory');
                break;
            case 'authorization-policy-copy':
                $container->setDefinition('test.policy.copy', clone $policy);
                break;
        }
    }

    public function getCacheDir(): string
    {
        return $this->directory;
    }

    public function getBuildDir(): string
    {
        return $this->directory;
    }

    public function getShareDir(): string
    {
        return $this->directory;
    }
}
