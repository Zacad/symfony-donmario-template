<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Cqrs;

use App\Kernel;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\CreateTask\CreateTaskHandler;
use App\Module\TaskTracking\Infrastructure\Framework\Symfony\Security\TaskTrackingVoter;
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
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
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

    /** Generated classes keep synthetic compilation scenarios explicit without changing production handlers. */
    public static function authorizationFixture(ContainerBuilder $container, string $scenario): void
    {
        $metadataScenarios = ['authorization-missing', 'authorization-duplicate', 'authorization-method', 'authorization-missing-voter', 'authorization-public-voter', 'authorization-public-permission', 'authorization-public-label', 'authorization-public-direct-voter', 'authorization-foreign-voter', 'authorization-foreign-permission', 'authorization-permission-integer', 'authorization-permission-suffix', 'authorization-permission-key', 'authorization-permission-prefix', 'authorization-permission-layer', 'authorization-invalid-label', 'authorization-missing-label', 'authorization-long-label', 'authorization-label-without-permission', 'authorization-invalid-metadata'];
        $voterScenarios = ['authorization-voter-missing', 'authorization-voter-duplicate', 'authorization-voter-public', 'authorization-voter-eager', 'authorization-voter-nonautowired', 'authorization-voter-autoconfigured', 'authorization-voter-security-tag', 'authorization-voter-tag-metadata', 'authorization-voter-copy', 'authorization-voter-unreferenced', 'authorization-voter-public-alias'];
        $managerScenarios = ['authorization-manager-public', 'authorization-strategy-public', 'authorization-strategy-allow-abstain', 'authorization-manager-reference', 'authorization-manager-voters-literal', 'authorization-manager-voters-tag', 'authorization-manager-voters-index', 'authorization-manager-voters-exclude', 'authorization-manager-voters-self'];
        if (!in_array($scenario, [...$metadataScenarios, ...$voterScenarios, ...$managerScenarios, 'accepted-data-result', 'void-result', 'authorization-public', 'authorization-valid'], true)) {
            return;
        }
        if (in_array($scenario, $voterScenarios, true)) {
            $voter = $container->getDefinition(TaskTrackingVoter::class);
            if ('authorization-voter-missing' === $scenario) {
                $voter->clearTag('app.authorization.voter');
            } elseif ('authorization-voter-duplicate' === $scenario) {
                $voter->addTag('app.authorization.voter');
            } elseif ('authorization-voter-public' === $scenario) {
                $voter->setPublic(true);
            } elseif ('authorization-voter-eager' === $scenario) {
                $voter->setLazy(false);
            } elseif ('authorization-voter-nonautowired' === $scenario) {
                $voter->setAutowired(false);
            } elseif ('authorization-voter-autoconfigured' === $scenario) {
                $voter->setAutoconfigured(true);
            } elseif ('authorization-voter-security-tag' === $scenario) {
                $voter->addTag('security.voter');
            } elseif ('authorization-voter-tag-metadata' === $scenario) {
                $voter->clearTag('app.authorization.voter');
                $voter->addTag('app.authorization.voter', ['message' => CreateTaskCommand::class]);
            } elseif ('authorization-voter-copy' === $scenario) {
                $container->setDefinition('test.voter.copy', clone $voter)->clearTag('app.authorization.voter');
            } elseif ('authorization-voter-unreferenced' === $scenario) {
                $container->setDefinition('test.voter.unreferenced', clone $voter);
            } else {
                $container->setAlias('test.voter.public.alias', TaskTrackingVoter::class)->setPublic(true);
            }

            return;
        }
        if (in_array($scenario, $managerScenarios, true)) {
            if ('authorization-manager-public' === $scenario) {
                $container->getDefinition('app.authorization.decision_manager')->setPublic(true);
            } elseif ('authorization-strategy-public' === $scenario) {
                $container->getDefinition('app.authorization.unanimous_strategy')->setPublic(true);
            } elseif ('authorization-strategy-allow-abstain' === $scenario) {
                $container->getDefinition('app.authorization.unanimous_strategy')->setArguments([true]);
            } elseif ('authorization-manager-reference' === $scenario) {
                $container->getDefinition(AuthorizationMiddleware::class)->setArgument(2, new Reference('app.authorization.unanimous_strategy'));
            } elseif ('authorization-manager-voters-literal' === $scenario) {
                $container->getDefinition('app.authorization.decision_manager')->setArgument(0, new IteratorArgument([]));
            } elseif ('authorization-manager-voters-tag' === $scenario) {
                $container->getDefinition('app.authorization.decision_manager')->setArgument(0, new TaggedIteratorArgument('security.voter'));
            } elseif ('authorization-manager-voters-index' === $scenario) {
                $container->getDefinition('app.authorization.decision_manager')->setArgument(0, new TaggedIteratorArgument('app.authorization.voter', 'message'));
            } elseif ('authorization-manager-voters-exclude' === $scenario) {
                $container->getDefinition('app.authorization.decision_manager')->setArgument(0, new TaggedIteratorArgument('app.authorization.voter', exclude: [TaskTrackingVoter::class]));
            } else {
                $container->getDefinition('app.authorization.decision_manager')->setArgument(0, new TaggedIteratorArgument('app.authorization.voter', excludeSelf: false));
            }

            return;
        }
        $namespace = 'App\\Module\\TaskTracking\\Application\\CreateTask';
        $suffix = ucfirst(substr(hash('sha256', $scenario), 0, 12));
        $handlerName = 'Compilation'.$suffix.'Handler';
        $handlerClass = $namespace.'\\'.$handlerName;
        $handler = $container->getDefinition(CreateTaskHandler::class);
        if (!class_exists($handlerClass, false)) {
            $voter = '\\App\\Module\\TaskTracking\\Infrastructure\\Framework\\Symfony\\Security\\TaskTrackingVoter::class';
            $permission = '\\App\\Module\\TaskTracking\\Domain\\TaskPermission::Create';
            if (str_starts_with($scenario, 'authorization-permission-')) {
                $permissionNamespace = 'authorization-permission-layer' === $scenario ? $namespace : 'App\\Module\\TaskTracking\\Domain';
                $permissionName = 'Compilation'.$suffix.('authorization-permission-suffix' === $scenario ? 'Capability' : 'Permission');
                $backingType = 'authorization-permission-integer' === $scenario ? 'int' : 'string';
                $value = match ($scenario) {
                    'authorization-permission-integer' => '1',
                    'authorization-permission-key' => '"TaskTracking.Invalid"',
                    'authorization-permission-prefix' => '"authorizing.manage"',
                    default => '"task_tracking.invalid"',
                };
                eval('namespace '.$permissionNamespace.'; enum '.$permissionName.': '.$backingType.' { case Invalid = '.$value.'; }');
                $permission = '\\'.$permissionNamespace.'\\'.$permissionName.'::Invalid';
            }
            $valid = '#[\\App\\Platform\\Authorization\\Authorize('.$voter.', '.$permission.', "Create tasks")]';
            $declaration = match ($scenario) {
                'authorization-missing', 'authorization-method' => '',
                'authorization-duplicate' => $valid.$valid,
                'authorization-missing-voter' => '#[\\App\\Platform\\Authorization\\Authorize]',
                'authorization-public' => '#[\\App\\Platform\\Authorization\\Authorize(public: true)]',
                'authorization-public-voter' => '#[\\App\\Platform\\Authorization\\Authorize(voter: '.$voter.', public: true)]',
                'authorization-public-permission' => '#[\\App\\Platform\\Authorization\\Authorize(permission: \\App\\Module\\TaskTracking\\Domain\\TaskPermission::Create, label: "Create tasks", public: true)]',
                'authorization-public-label' => '#[\\App\\Platform\\Authorization\\Authorize(label: "Create tasks", public: true)]',
                'authorization-public-direct-voter' => '#[\\App\\Platform\\Authorization\\Authorize(\\App\\Platform\\Authorization\\PublicAccessVoter::class)]',
                'authorization-foreign-voter' => '#[\\App\\Platform\\Authorization\\Authorize(\\App\\Module\\Authenticating\\Infrastructure\\Framework\\Symfony\\Security\\AuthenticatingVoter::class)]',
                'authorization-foreign-permission' => '#[\\App\\Platform\\Authorization\\Authorize('.$voter.', \\App\\Module\\Authorizing\\Domain\\Capability\\AuthorizingPermissionEnum::Manage, "Manage authorization assignments")]',
                'authorization-invalid-label' => '#[\\App\\Platform\\Authorization\\Authorize('.$voter.', \\App\\Module\\TaskTracking\\Domain\\TaskPermission::Create, " ")]',
                'authorization-missing-label' => '#[\\App\\Platform\\Authorization\\Authorize('.$voter.', \\App\\Module\\TaskTracking\\Domain\\TaskPermission::Create)]',
                'authorization-long-label' => '#[\\App\\Platform\\Authorization\\Authorize('.$voter.', \\App\\Module\\TaskTracking\\Domain\\TaskPermission::Create, "'.str_repeat('a', 101).'")]',
                'authorization-label-without-permission' => '#[\\App\\Platform\\Authorization\\Authorize('.$voter.', label: "Create tasks")]',
                'authorization-invalid-metadata' => '#[\\App\\Platform\\Authorization\\Authorize("not-a-class")]',
                default => $valid,
            };
            $methodAttribute = 'authorization-method' === $scenario ? $valid : '';
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
