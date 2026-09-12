<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class InvocationMiddleware implements MiddlewareInterface
{
    public function __construct(private InvocationContext $context, private ManagerRegistry $doctrine, private string $kind, private BestEffortEventDispatcher $events)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->context->assertUsable();
        $root = $this->context->isRoot();
        $entered = false;
        $completed = false;
        try {
            $this->context->enter($this->kind);
            $entered = true;

            $handled = $stack->next()->handle($envelope, $stack);
            $completed = true;

            return $handled;
        } catch (\Throwable $failure) {
            $this->context->fail($failure);
            throw $failure;
        } finally {
            if ($entered) {
                $this->context->leave();
            }
            if ($root) {
                [$pending, $dropped] = $this->context->pendingEvents();
                $clean = false;
                try {
                    // Reset remains lazy; held repositories keep the manager identity.
                    $this->doctrine->resetManager('default');
                    $clean = true;
                } catch (\Throwable $cleanupFailure) {
                    $this->context->markUnusable();
                    $this->events->cleanupFailed($cleanupFailure, count($pending) + $dropped);
                    if (!$completed || 'command' !== $this->kind) {
                        throw $cleanupFailure;
                    }
                } finally {
                    $this->context->reset();
                }
                if ($completed && $clean && 'command' === $this->kind) {
                    $this->events->deliver($pending, $dropped);
                }
            }
        }
    }
}
