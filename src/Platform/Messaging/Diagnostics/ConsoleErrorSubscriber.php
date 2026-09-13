<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Diagnostics;

use App\Platform\Messaging\InvocationContext;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;

final class ConsoleErrorSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly InvocationContext $context)
    {
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        if (in_array($event->getCommand()?->getName(), ['messenger:consume', 'messenger:failed:show', 'messenger:failed:retry', 'messenger:failed:remove'], true)) {
            $exitCode = $event->getExitCode();
            $event->setError(self::safeError());
            $event->setExitCode($exitCode);
        }
    }

    public function redactErrorDetails(WorkerMessageFailedEvent $event): void
    {
        $safe = FlattenException::createFromThrowable(self::safeError());
        $safe->setTrace([], '', 0)->setFile('')->setLine(0);
        $stamp = new ErrorDetailsStamp(\RuntimeException::class, 0, $safe->getMessage(), $safe);

        // Symfony 8.1 exposes addStamps() but no removal/setEnvelope hook. Replace
        // only diagnostic stamps: appending would persist the original secrets.
        // The actual throwable and every other stamp remain native and untouched.
        $envelope = $event->getEnvelope()->withoutAll(ErrorDetailsStamp::class)->with($stamp);
        $event->__construct($envelope, $event->getReceiverName(), $event->getThrowable());
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (!$this->context->isUsable()) {
            $event->getWorker()->stop();
        }
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        // Stock failed:retry may start another Worker for each requested ID.
        $this->context->assertUsable();
    }

    private static function safeError(): \RuntimeException
    {
        $error = new \RuntimeException('messenger.failure: details suppressed.');
        // Even this new exception's call arguments could reference the raw error.
        (new \ReflectionProperty(\Exception::class, 'trace'))->setValue($error, []);

        return $error;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::ERROR => ['onConsoleError', 2048],
            WorkerMessageFailedEvent::class => ['redactErrorDetails', 150],
            WorkerRunningEvent::class => ['onWorkerRunning', 2048],
            WorkerMessageReceivedEvent::class => ['onMessageReceived', 2048],
        ];
    }
}
