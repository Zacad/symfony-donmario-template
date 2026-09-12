<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

/** A delivery session outlives each independent listener command root. */
final class EventDeliveryContext
{
    private bool $active = false;
    /** @var list<object> */
    private array $queue = [];
    private int $cursor = 0;
    private int $dropped = 0;
    private ?object $authorized = null;

    public function isActive(): bool
    {
        return $this->active;
    }

    /** @param list<object> $events */
    public function append(array $events, int $dropped): void
    {
        $this->active = true;
        $this->dropped += $dropped;
        foreach ($events as $event) {
            if (100 === count($this->queue)) {
                ++$this->dropped;
            } else {
                $this->queue[] = $event;
            }
        }
    }

    public function next(): ?object
    {
        $this->authorized = $this->queue[$this->cursor++] ?? null;

        return $this->authorized;
    }

    public function authorize(object $event): void
    {
        if (!$this->active || $this->authorized !== $event) {
            throw new \LogicException('event.scope: only the after-commit dispatcher may deliver events.');
        }
        // Consume before listeners run: even redispatching the same object fails.
        $this->authorized = null;
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    public function reset(): void
    {
        $this->active = false;
        $this->queue = [];
        $this->cursor = 0;
        $this->dropped = 0;
        $this->authorized = null;
    }
}
