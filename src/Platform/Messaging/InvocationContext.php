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
    private int $authorizationDecisionDepth = 0;
    private ?\Closure $invalidateTransaction = null;

    public function isRoot(): bool
    {
        return [] === $this->stack;
    }

    public function enter(string $kind): void
    {
        $this->assertUsable();
        $this->assertNotLifecycle();
        if ('command' === $kind && $this->inAuthorizationDecision()) {
            throw new \LogicException('authorization.decision_write: Authorization decisions cannot dispatch commands.');
        }
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
        if ($this->inAuthorizationDecision() || in_array('command', $this->stack, true)) {
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

    public function assertEventDispatchAllowed(): void
    {
        $this->assertUsable();
        $this->assertNotLifecycle();
        if ($this->inAuthorizationDecision()) {
            throw new \LogicException('authorization.decision_write: Authorization decisions cannot dispatch events.');
        }
        if (!$this->transaction || !$this->handlerTime || in_array('query', $this->stack, true) || 'command' !== end($this->stack)) {
            throw new \LogicException('event.scope: dispatch requires an owned command handler.');
        }
        $this->assertHealthy();
    }

    public function markUnusable(): void
    {
        $this->unusable = true;
    }

    public function enterAuthorizationDecision(): void
    {
        ++$this->authorizationDecisionDepth;
    }

    public function leaveAuthorizationDecision(): void
    {
        --$this->authorizationDecisionDepth;
    }

    public function inAuthorizationDecision(): bool
    {
        return $this->authorizationDecisionDepth > 0;
    }

    public function assertUsable(): void
    {
        if ($this->unusable) {
            throw new \LogicException('cqrs.unusable: replace the runtime after failed ORM cleanup.');
        }
    }

    public function isUsable(): bool
    {
        return !$this->unusable;
    }

    private function assertNotLifecycle(): void
    {
        // Explicit handler-time dispatch only, including callbacks triggered by
        // persist/load while the handler is still on the stack. No ORM hooks.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (('Doctrine\\ORM\\Event\\ListenersInvoker' === ($frame['class'] ?? '') && 'invoke' === $frame['function'])
                || (is_a($frame['class'] ?? '', 'Doctrine\\Common\\EventManager', true) && 'dispatchEvent' === $frame['function'])) {
                throw new \LogicException('cqrs.phase: ORM lifecycle callbacks cannot dispatch.');
            }
        }
    }

    public function reset(): void
    {
        $this->stack = [];
        $this->failure = null;
        $this->transaction = false;
        $this->handlerTime = false;
        $this->authorizationDecisionDepth = 0;
        $this->invalidateTransaction = null;
    }
}
