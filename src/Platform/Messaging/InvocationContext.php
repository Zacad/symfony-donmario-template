<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

/** Synchronous invocation-local state; never exposed to module services. */
final class InvocationContext
{
    /** @var list<string> */
    private array $stack = [];
    private ?\Throwable $failure = null;
    private bool $transaction = false;
    private bool $handlerTime = false;
    private bool $unusable = false;
    private ?\Closure $invalidateTransaction = null;
    /** @var list<object> */
    private array $events = [];
    private int $dropped = 0;

    public function isRoot(): bool
    {
        return [] === $this->stack;
    }

    public function enter(string $kind): void
    {
        $this->assertUsable();
        $this->assertNotLifecycle();
        if ($this->transaction && !$this->handlerTime) {
            throw new \LogicException('cqrs.phase: messaging requires handler execution.');
        }
        if ('command' === $kind && in_array('query', $this->stack, true)) {
            throw new \LogicException('cqrs.query_write: queries cannot dispatch commands.');
        }
        $this->stack[] = $kind;
    }

    public function leave(): void
    {
        array_pop($this->stack);
    }

    public function fail(\Throwable $failure): void
    {
        if (in_array('command', $this->stack, true)) {
            $this->failure ??= $failure;
            if (null !== $this->invalidateTransaction) {
                ($this->invalidateTransaction)();
            }
        }
    }

    public function assertHealthy(): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    public function ownsTransaction(): bool
    {
        return $this->transaction;
    }

    public function startTransaction(\Closure $invalidateTransaction): void
    {
        $this->transaction = true;
        $this->invalidateTransaction = $invalidateTransaction;
    }

    public function finishTransaction(): void
    {
        $this->transaction = false;
        $this->invalidateTransaction = null;
    }

    public function handlerTime(bool $active): void
    {
        $this->handlerTime = $active;
    }

    public function record(object $event): void
    {
        $this->assertUsable();
        $this->assertNotLifecycle();
        if (!$this->transaction || !$this->handlerTime || in_array('query', $this->stack, true) || 'command' !== end($this->stack)) {
            throw new \LogicException('event.scope: recording requires an owned command handler.');
        }
        $this->assertHealthy();
        if (100 === count($this->events)) {
            ++$this->dropped;

            return;
        }
        $this->events[] = $event;
    }

    /** @return array{list<object>, int} */
    public function pendingEvents(): array
    {
        return [$this->events, $this->dropped];
    }

    public function markUnusable(): void
    {
        $this->unusable = true;
    }

    public function assertUsable(): void
    {
        if ($this->unusable) {
            throw new \LogicException('cqrs.unusable: replace the runtime after failed ORM cleanup.');
        }
    }

    private function assertNotLifecycle(): void
    {
        // Explicit handler-time recording only, including callbacks triggered by
        // persist/load while the handler is still on the stack. No ORM hooks.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (('Doctrine\\ORM\\Event\\ListenersInvoker' === ($frame['class'] ?? '') && 'invoke' === $frame['function'])
                || (is_a($frame['class'] ?? '', 'Doctrine\\Common\\EventManager', true) && 'dispatchEvent' === $frame['function'])) {
                throw new \LogicException('cqrs.phase: ORM lifecycle callbacks cannot record or dispatch.');
            }
        }
    }

    public function reset(): void
    {
        $this->stack = [];
        $this->failure = null;
        $this->transaction = false;
        $this->handlerTime = false;
        $this->invalidateTransaction = null;
        $this->events = [];
        $this->dropped = 0;
    }
}
