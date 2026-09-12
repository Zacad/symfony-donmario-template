<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\TaskTracking\Application\CreateTask\TaskCreatedEvent;
use App\Module\TaskTracking\Domain\Event\TaskCreatedEvent as DomainTaskCreatedEvent;
use App\Module\TaskTracking\Domain\Task;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\Recording\RecordsDomainEvents;
use App\Platform\Event\Recording\RecordsDomainEventsTrait;
use App\Platform\Messaging\ApplicationEventRecorder;
use App\Platform\Messaging\BestEffortEventDispatcher;
use App\Platform\Messaging\EventDeliveryContext;
use App\Platform\Messaging\EventPolicyMiddleware;
use App\Platform\Messaging\InvocationContext;
use App\Platform\Messaging\InvocationMiddleware;
use App\Tests\Fixtures\EventCompilation\RuntimeListener;
use Doctrine\Common\EventManager;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\AddBusNameStampMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

final class EventRuntimeTest extends TestCase
{
    public function testDomainEventsAreLocalAndReleasedOnce(): void
    {
        $task = new Task('Local event');
        self::assertInstanceOf(RecordsDomainEvents::class, $task);
        $events = $task->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(DomainTaskCreatedEvent::class, $events[0]);
        self::assertSame($task->id(), $events[0]->taskId);
        self::assertSame([], $task->releaseEvents());
    }

    public function testOptInRecordingPreservesOrderAndSeparatesObjectsAndBatches(): void
    {
        $first = new class implements RecordsDomainEvents {
            use RecordsDomainEventsTrait;

            public function happen(DomainEvent $event): void
            {
                $this->recordDomainEvent($event);
            }
        };
        $second = clone $first;
        $a = new DomainTaskCreatedEvent(Uuid::v7());
        $b = new readonly class extends DomainEvent {};
        $c = new DomainTaskCreatedEvent(Uuid::v7());

        self::assertSame([], $first->releaseEvents());
        $first->happen($a);
        $second->happen($c);
        $first->happen($b);
        $released = $first->releaseEvents();
        self::assertSame([$a, $b], $released);
        self::assertSame([], $first->releaseEvents());
        self::assertSame([$c], $second->releaseEvents());

        $first->happen($c);
        self::assertSame([$c], $first->releaseEvents());
        self::assertSame([$a, $b], $released, 'Later recording does not mutate a released batch.');
        self::assertSame([], $second->releaseEvents());
        self::assertTrue(new \ReflectionMethod($first, 'recordDomainEvent')->isProtected());
    }

    public function testStandardMessengerAttemptsAllListenersThenContinuesTheFifoSession(): void
    {
        $context = new InvocationContext();
        $delivery = new EventDeliveryContext();
        $logger = $this->logger();
        $order = [];
        $first = new TaskCreatedEvent(Uuid::v7());
        $second = new TaskCreatedEvent(Uuid::v7());
        $child = new TaskCreatedEvent(Uuid::v7());
        $dispatcher = null;
        $listeners = [
            new HandlerDescriptor(new RuntimeListener(static function (TaskCreatedEvent $event) use (&$order, &$dispatcher, $first, $child, $context): void {
                self::assertTrue($context->isRoot());
                $order[] = [$event, 'first'];
                if ($event === $first) {
                    self::assertInstanceOf(BestEffortEventDispatcher::class, $dispatcher);
                    // A successful independent listener command has already committed.
                    $dispatcher->deliver([$child]);
                    throw new \RuntimeException('SECRET payload SQL credentials');
                }
            })),
            new HandlerDescriptor(static function (TaskCreatedEvent $event) use (&$order): void {
                $order[] = [$event, 'second'];
            }),
        ];
        $bus = $this->eventBus($context, $delivery, $listeners);
        $dispatcher = new BestEffortEventDispatcher($delivery, $bus, $logger);
        $dispatcher->deliver([$first, $second]);
        self::assertSame([[$first, 'first'], [$first, 'second'], [$second, 'first'], [$second, 'second'], [$child, 'first'], [$child, 'second']], $order);
        self::assertFalse($delivery->isActive());
        self::assertCount(1, $logger->entries);
        self::assertSame('event.delivery_failed', $logger->entries[0][0]);
        self::assertSame(['event_class' => TaskCreatedEvent::class, 'listener' => RuntimeListener::class.'::__invoke', 'exception_class' => \RuntimeException::class], $logger->entries[0][1]);
    }

    public function testDiagnosticsRejectUnsafeHandlerAliasesAndNeverLogExceptionMessages(): void
    {
        $context = new InvocationContext();
        $delivery = new EventDeliveryContext();
        $logger = $this->logger();
        $listener = new HandlerDescriptor(new RuntimeListener(static function (TaskCreatedEvent $event): void {
            throw new \RuntimeException('SECRET SQL and payload');
        }), ['alias' => "unsafe\nSECRET payload"]);
        $bus = $this->eventBus($context, $delivery, [$listener]);
        new BestEffortEventDispatcher($delivery, $bus, $logger)->deliver([new TaskCreatedEvent(Uuid::v7())]);
        self::assertSame([['event.delivery_failed', [
            'event_class' => TaskCreatedEvent::class,
            'listener' => 'unknown',
            'exception_class' => \RuntimeException::class,
        ]]], $logger->entries);
    }

    public function testBoundsCountEveryDiscardAndResetBetweenSessionsEvenIfLoggingThrows(): void
    {
        $context = new InvocationContext();
        $delivery = new EventDeliveryContext();
        $logger = $this->logger();
        $recorder = new ApplicationEventRecorder($context, [TaskCreatedEvent::class => 'event']);
        $context->enter('command');
        $context->startTransaction(static function (): void {});
        $context->handlerTime(true);
        $event = new TaskCreatedEvent(Uuid::v7());
        for ($i = 0; $i < 105; ++$i) {
            $recorder->record($event);
        }
        $context->assertHealthy();
        [$pending, $dropped] = $context->pendingEvents();
        self::assertCount(100, $pending);
        self::assertSame(5, $dropped);
        $context->reset();
        $attempts = 0;
        $dispatcher = null;
        $bus = $this->eventBus($context, $delivery, [static function (TaskCreatedEvent $event) use (&$dispatcher, &$attempts): void {
            ++$attempts;
            self::assertInstanceOf(BestEffortEventDispatcher::class, $dispatcher);
            $dispatcher->deliver([$event]);
        }]);
        $dispatcher = new BestEffortEventDispatcher($delivery, $bus, $logger);
        $dispatcher->deliver($pending, $dropped);
        self::assertSame(100, $attempts);
        self::assertSame([['event.overflow', ['dropped_count' => 105]]], $logger->entries);
        $logger->throw = true;
        $dispatcher->deliver([$event]);
        self::assertSame(200, $attempts);
        self::assertFalse($delivery->isActive());
    }

    public function testInvalidObjectsAndQueryScopePoisonTheRootEvenWhenCaught(): void
    {
        foreach ([new \stdClass(), new DomainTaskCreatedEvent(Uuid::v7()), new Envelope(new TaskCreatedEvent(Uuid::v7()))] as $invalid) {
            $context = new InvocationContext();
            $context->enter('command');
            $invalidated = false;
            $context->startTransaction(static function () use (&$invalidated): void { $invalidated = true; });
            $context->handlerTime(true);
            $recorder = new ApplicationEventRecorder($context, [TaskCreatedEvent::class => 'event']);
            try {
                $recorder->record($invalid);
                self::fail('Expected invalid event rejection.');
            } catch (\LogicException $failure) {
                self::assertStringStartsWith('event.message:', $failure->getMessage());
            }
            self::assertTrue($invalidated);
            try {
                $context->assertHealthy();
                self::fail('Caught event rejection must poison the root.');
            } catch (\LogicException) {
            }
        }
        $context = new InvocationContext();
        $recorder = new ApplicationEventRecorder($context, [TaskCreatedEvent::class => 'event']);
        $event = new TaskCreatedEvent(Uuid::v7());
        foreach (['outside', 'query'] as $scope) {
            if ('query' === $scope) {
                $context->enter('command');
                $context->startTransaction(static function (): void {});
                $context->handlerTime(true);
                $context->enter('query');
            }
            try {
                $recorder->record($event);
                self::fail('Expected recording scope rejection.');
            } catch (\LogicException $failure) {
                self::assertStringStartsWith('event.scope:', $failure->getMessage());
            }
        }
    }

    public function testZeroListenersAndOneShotDrainAuthorization(): void
    {
        $context = new InvocationContext();
        $delivery = new EventDeliveryContext();
        $logger = $this->logger();
        $event = new TaskCreatedEvent(Uuid::v7());
        $bus = $this->eventBus($context, $delivery);
        new BestEffortEventDispatcher($delivery, $bus, $logger)->deliver([$event]);
        self::assertSame([], $logger->entries);
        try {
            $bus->dispatch($event);
            self::fail('Raw dispatch must require the dispatcher.');
        } catch (\LogicException $failure) {
            self::assertStringStartsWith('event.scope:', $failure->getMessage());
        }
        $bus = null;
        $bus = $this->eventBus($context, $delivery, [static function (TaskCreatedEvent $event) use (&$bus): void {
            self::assertInstanceOf(MessageBus::class, $bus);
            $bus->dispatch($event);
        }]);
        new BestEffortEventDispatcher($delivery, $bus, $logger)->deliver([$event]);
        self::assertCount(1, $logger->entries);
        self::assertFalse($delivery->isActive());
    }

    public function testCommittedBatchesWaitForResetAndListenerCommandsHaveFreshRoots(): void
    {
        $context = new InvocationContext();
        $delivery = new EventDeliveryContext();
        $recorder = new ApplicationEventRecorder($context, [TaskCreatedEvent::class => 'event']);
        $logger = $this->logger();
        $order = [];
        $manager = $this->createStub(ManagerRegistry::class);
        $orm = $this->createStub(ObjectManager::class);
        $manager->method('resetManager')->willReturnCallback(static function () use (&$order, $orm): ObjectManager {
            $order[] = 'reset';

            return $orm;
        });
        $producer = new TaskCreatedEvent(Uuid::v7());
        $child = new TaskCreatedEvent(Uuid::v7());
        $commands = null;
        $eventBus = $this->eventBus($context, $delivery, [static function (TaskCreatedEvent $event) use (&$commands, &$order, $context, $producer): void {
            self::assertTrue($context->isRoot());
            self::assertFalse($context->ownsTransaction());
            $order[] = $event === $producer ? 'producer-event' : 'child-event';
            if ($event === $producer) {
                self::assertInstanceOf(MessageBus::class, $commands);
                $commands->dispatch(new EventRuntimeCommand('child'));
                throw new \RuntimeException('Listener failed after its command committed.');
            }
        }]);
        $dispatcher = new BestEffortEventDispatcher($delivery, $eventBus, $logger);
        $handle = static function (Envelope $envelope) use ($context, $recorder, &$order, $producer, $child): Envelope {
            self::assertFalse($context->ownsTransaction());
            $message = $envelope->getMessage();
            self::assertInstanceOf(EventRuntimeCommand::class, $message);
            $context->startTransaction(static function (): void {});
            $context->handlerTime(true);
            try {
                $recorder->record('child' === $message->name ? $child : $producer);
                if ('failed' === $message->name) {
                    throw new \RuntimeException('Producer failure');
                }
                $context->assertHealthy();
                $order[] = 'commit-'.$message->name;

                return $envelope->with(new HandledStamp($message->name, 'producer'));
            } finally {
                $context->handlerTime(false);
                $context->finishTransaction();
            }
        };
        $commands = new MessageBus([
            new InvocationMiddleware($context, $manager, 'command', $dispatcher),
            new class($handle) implements MiddlewareInterface {
                /** @param \Closure(Envelope): Envelope $handle */
                public function __construct(private \Closure $handle)
                {
                }

                public function handle(Envelope $envelope, StackInterface $stack): Envelope
                {
                    return ($this->handle)($envelope);
                }
            },
        ]);
        try {
            $commands->dispatch(new EventRuntimeCommand('failed'));
            self::fail('Expected producer failure.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Producer failure', $failure->getMessage());
        }
        self::assertSame(['reset'], $order);
        $order = [];
        $result = $commands->dispatch(new EventRuntimeCommand('producer'));
        self::assertSame('producer', $result->last(HandledStamp::class)?->getResult());
        self::assertSame(['commit-producer', 'reset', 'producer-event', 'commit-child', 'reset', 'child-event'], $order);
        self::assertCount(1, $logger->entries);
        self::assertTrue($context->isRoot());
        [$pending, $dropped] = $context->pendingEvents();
        self::assertCount(0, $pending);
        self::assertSame(0, $dropped);
    }

    public function testCaughtLifecycleRecordingAndDispatchInvalidateTheOwnedTransaction(): void
    {
        foreach (['record', 'dispatch'] as $operation) {
            $context = new InvocationContext();
            $context->enter('command');
            $invalidated = false;
            $context->startTransaction(static function () use (&$invalidated): void { $invalidated = true; });
            $context->handlerTime(true);
            $recorder = new ApplicationEventRecorder($context, [TaskCreatedEvent::class => 'event']);
            $callback = static function () use ($operation, $recorder, $context): void {
                if ('record' === $operation) {
                    $recorder->record(new TaskCreatedEvent(Uuid::v7()));
                } else {
                    try {
                        $context->enter('query');
                    } catch (\Throwable $failure) {
                        $context->fail($failure);
                        throw $failure;
                    }
                }
            };
            $listener = new class($callback) {
                public ?\Throwable $caught = null;

                public function __construct(private \Closure $callback)
                {
                }

                public function onFlush(): void
                {
                    try {
                        ($this->callback)();
                    } catch (\Throwable $failure) {
                        $this->caught = $failure;
                    }
                }
            };
            $events = new EventManager();
            $events->addEventListener(['onFlush'], $listener);
            $events->dispatchEvent('onFlush');
            self::assertInstanceOf(\LogicException::class, $listener->caught);
            self::assertStringStartsWith('cqrs.phase:', $listener->caught->getMessage());
            self::assertTrue($invalidated);
        }
    }

    public function testCleanupFailurePreservesKnownCommittedResultsAndDisablesTheRuntime(): void
    {
        foreach ([false, true] as $fail) {
            $context = new InvocationContext();
            $delivery = new EventDeliveryContext();
            $order = [];
            $manager = $this->createStub(ManagerRegistry::class);
            $manager->method('resetManager')->willReturnCallback(static function () use (&$order): never {
                $order[] = 'reset';
                throw new \RuntimeException('RESET SECRET');
            });
            $logger = $this->logger();
            $logger->throw = true;
            $dispatcher = new BestEffortEventDispatcher($delivery, $this->eventBus($context, $delivery, [static function () use (&$order): void { $order[] = 'delivered'; }]), $logger);
            $bus = new MessageBus([
                new InvocationMiddleware($context, $manager, 'command', $dispatcher),
                new class($context, $fail) implements MiddlewareInterface {
                    public function __construct(private InvocationContext $context, private bool $fail)
                    {
                    }

                    public function handle(Envelope $envelope, StackInterface $stack): Envelope
                    {
                        $this->context->startTransaction(static function (): void {});
                        $this->context->handlerTime(true);
                        $this->context->record(new TaskCreatedEvent(Uuid::v7()));
                        if ($this->fail) {
                            throw new \RuntimeException('Producer failure');
                        }
                        $this->context->finishTransaction();

                        return $envelope->with(new HandledStamp('committed', 'producer'));
                    }
                },
            ]);
            try {
                $handled = $bus->dispatch(new \stdClass());
                self::assertFalse($fail);
                self::assertSame('committed', $handled->last(HandledStamp::class)?->getResult());
            } catch (\RuntimeException $failure) {
                self::assertTrue($fail);
                self::assertSame('RESET SECRET', $failure->getMessage());
            }
            self::assertSame(['reset'], $order);
            self::assertTrue($context->isRoot());
            self::assertSame([[], 0], $context->pendingEvents());
            try {
                $bus->dispatch(new \stdClass());
                self::fail('The contaminated runtime must not continue.');
            } catch (\LogicException $failure) {
                self::assertStringStartsWith('cqrs.unusable:', $failure->getMessage());
            }
            self::assertSame(['reset'], $order);
        }
    }

    /** @param list<callable|HandlerDescriptor> $listeners */
    private function eventBus(InvocationContext $context, EventDeliveryContext $delivery, array $listeners = []): MessageBus
    {
        return new MessageBus([
            new EventPolicyMiddleware($delivery, $context, [TaskCreatedEvent::class => 'event']),
            new AddBusNameStampMiddleware('application.event.bus'),
            new HandleMessageMiddleware(new HandlersLocator([TaskCreatedEvent::class => $listeners]), true),
        ]);
    }

    private function logger(): EventTestLogger
    {
        return new EventTestLogger();
    }
}

final class EventTestLogger extends AbstractLogger
{
    /** @var list<array{string, array<mixed>}> */
    public array $entries = [];
    public bool $throw = false;

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->entries[] = [(string) $message, $context];
        if ($this->throw) {
            throw new \RuntimeException('LOGGER SECRET');
        }
    }
}

final readonly class EventRuntimeCommand
{
    public function __construct(public string $name)
    {
    }
}
