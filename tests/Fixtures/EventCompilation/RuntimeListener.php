<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\EventCompilation;

use App\Module\TaskTracking\Application\CreateTask\TaskCreatedEvent;

/** A named callable so runtime tests exercise Messenger's actual listener identity. */
final readonly class RuntimeListener
{
    /** @param \Closure(TaskCreatedEvent): void $callback */
    public function __construct(private \Closure $callback)
    {
    }

    public function __invoke(TaskCreatedEvent $event): void
    {
        ($this->callback)($event);
    }
}
