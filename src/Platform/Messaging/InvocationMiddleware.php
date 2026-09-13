<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class InvocationMiddleware implements MiddlewareInterface
{
    public function __construct(private InvocationContext $context, private ManagerRegistry $doctrine, private string $kind, private LoggerInterface $logger)
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
                try {
                    // Reset remains lazy; held repositories keep the manager identity.
                    $this->doctrine->resetManager('default');
                } catch (\Throwable $cleanupFailure) {
                    $this->context->markUnusable();
                    try {
                        $this->logger->warning('cqrs.cleanup_failed', [
                            'exception_class' => new \ReflectionClass($cleanupFailure)->isAnonymous() ? 'anonymous' : $cleanupFailure::class,
                        ]);
                    } catch (\Throwable) {
                        // Logging cannot replace a committed command result.
                    }
                    if (!$completed || 'command' !== $this->kind) {
                        throw $cleanupFailure;
                    }
                } finally {
                    $this->context->reset();
                }
            }
        }
    }
}
