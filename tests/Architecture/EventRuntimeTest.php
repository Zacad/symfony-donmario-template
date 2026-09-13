<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\TaskTracking\Domain\Event\TaskCreatedEvent;
use App\Module\TaskTracking\Domain\Task;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\Recording\RecordsDomainEvents;
use App\Platform\Event\Recording\RecordsDomainEventsTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class EventRuntimeTest extends TestCase
{
    public function testDomainEventsAreLocalAndReleasedOnce(): void
    {
        $task = new Task('Local event');
        self::assertInstanceOf(RecordsDomainEvents::class, $task);
        $events = $task->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TaskCreatedEvent::class, $events[0]);
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
        $a = new TaskCreatedEvent(Uuid::v7());
        $b = new readonly class extends DomainEvent {};
        $c = new TaskCreatedEvent(Uuid::v7());
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
        self::assertSame([$a, $b], $released);
        self::assertSame([], $second->releaseEvents());
        self::assertTrue(new \ReflectionMethod($first, 'recordDomainEvent')->isProtected());
    }
}
