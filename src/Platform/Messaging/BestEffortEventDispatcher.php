<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class BestEffortEventDispatcher
{
    public function __construct(
        private EventDeliveryContext $context,
        #[Autowire(service: 'application.event.bus')] private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /** @param list<object> $events */
    public function deliver(array $events, int $dropped = 0): void
    {
        $active = $this->context->isActive();
        $this->context->append($events, $dropped);
        if ($active) {
            return;
        }
        try {
            while (null !== $event = $this->context->next()) {
                try {
                    $this->bus->dispatch(new Envelope($event));
                } catch (\Throwable $failure) {
                    $failures = $failure instanceof HandlerFailedException ? $failure->getWrappedExceptions() : [$failure];
                    foreach ($failures as $listener => $cause) {
                        $this->diagnostic('event.delivery_failed', [
                            'event_class' => $event::class,
                            'listener' => $this->listenerIdentity($listener),
                            'exception_class' => $this->exceptionClass($cause),
                        ]);
                    }
                }
            }
            if (0 < $this->context->dropped()) {
                $this->diagnostic('event.overflow', ['dropped_count' => $this->context->dropped()]);
            }
        } finally {
            $this->context->reset();
        }
    }

    public function cleanupFailed(\Throwable $failure, int $discarded): void
    {
        $this->diagnostic('event.cleanup_failed', ['exception_class' => $this->exceptionClass($failure), 'discarded_count' => $discarded]);
    }

    private function exceptionClass(\Throwable $failure): string
    {
        return new \ReflectionClass($failure)->isAnonymous() ? 'anonymous' : $failure::class;
    }

    private function listenerIdentity(string|int $listener): string
    {
        if (!is_string($listener)) {
            return 'unknown';
        }
        $prefix = EventListenerInvoker::class.'::__invoke@';
        if (str_starts_with($listener, $prefix)) {
            $listener = substr($listener, strlen($prefix));
        }

        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\x5c[A-Za-z_][A-Za-z0-9_]*)*(?:::[A-Za-z_][A-Za-z0-9_]*)?\z/', $listener) ? $listener : 'unknown';
    }

    /** @param array<string, string|int> $context */
    private function diagnostic(string $message, array $context): void
    {
        try {
            $this->logger->warning($message, $context);
        } catch (\Throwable) {
            // Logging is itself best effort and cannot negate a committed result.
        }
    }
}
