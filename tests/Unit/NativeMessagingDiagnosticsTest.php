<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\Event\ApplicationEvent;
use App\Platform\Messaging\Diagnostics\ConsoleErrorSubscriber;
use App\Platform\Messaging\Diagnostics\SafeFailedMessagesRemoveCommand;
use App\Platform\Messaging\Diagnostics\SafeFailedMessagesRetryCommand;
use App\Platform\Messaging\Diagnostics\SafeFailedMessagesShowCommand;
use App\Platform\Messaging\Diagnostics\SafeMessengerLogger;
use App\Platform\Messaging\InvocationContext;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\Normalizer\FlattenExceptionNormalizer;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer as SymfonySerializer;

final class NativeMessagingDiagnosticsTest extends TestCase
{
    private const CANARY = 'credential-payload-canary-17';

    /** @return iterable<string, array{\Throwable, int, bool, int}> */
    public static function failures(): iterable
    {
        yield 'ordinary retry' => [new \RuntimeException(self::CANARY), 3, true, 1000];
        yield 'unrecoverable stays unrecoverable' => [new UnrecoverableMessageHandlingException(self::CANARY), 3, false, 0];
        yield 'recoverable retains custom delay and forced retry' => [new RecoverableMessageHandlingException(self::CANARY, retryDelay: 4321), 0, true, 4321];
        yield 'ordinary exhausted' => [new \RuntimeException(self::CANARY), 0, false, 0];
    }

    #[DataProvider('failures')]
    public function testNativeRetryClassificationAndPartialHandlingSurviveRedaction(\Throwable $failure, int $maxRetries, bool $retry, int $delay): void
    {
        $logs = new TestHandler();
        $logger = new SafeMessengerLogger(new Logger('messenger', [$logs]));
        $calls = 0;
        $state = new class {
            public bool $fail = true;
        };
        $middleware = new HandleMessageMiddleware(new HandlersLocator([
            DiagnosticCanaryEvent::class => [
                new HandlerDescriptor(static function () use (&$calls): void {
                    ++$calls;
                }, ['alias' => 'success']),
                new HandlerDescriptor(static function () use ($state, $failure): void {
                    if ($state->fail) {
                        throw $failure;
                    }
                }, ['alias' => 'failure']),
            ],
        ]));
        $middleware->setLogger($logger);
        $bus = new MessageBus([$middleware]);
        try {
            $bus->dispatch(new DiagnosticCanaryEvent(self::CANARY));
            self::fail('The native handler should fail.');
        } catch (HandlerFailedException $caught) {
            $event = new WorkerMessageFailedEvent($caught->getEnvelope(), 'events', $caught);
        }

        $sent = new InMemoryTransport();
        $failed = new InMemoryTransport();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AddErrorDetailsStampListener());
        $dispatcher->addSubscriber(new ConsoleErrorSubscriber(new InvocationContext()));
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            new ServiceLocator(['events' => static fn () => $sent]),
            new ServiceLocator(['events' => static fn () => new MultiplierRetryStrategy($maxRetries, 1000, jitter: 0)]),
            $logger,
        ));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(new ServiceLocator(['events' => static fn () => $failed]), $logger));
        $dispatcher->dispatch($event);

        self::assertSame($caught, $event->getThrowable());
        self::assertSame($failure, $caught->getPrevious());
        self::assertSame($retry, $event->willRetry());
        $stored = ($retry ? $sent : $failed)->getSent()[0];
        self::assertSame($delay, $stored->last(DelayStamp::class)?->getDelay());
        self::assertCount(1, $stored->all(HandledStamp::class));
        self::assertCount(1, $stored->all(ErrorDetailsStamp::class));

        $serializer = new Serializer(new SymfonySerializer([
            new FlattenExceptionNormalizer(), new DateTimeNormalizer(), new ArrayDenormalizer(), new ObjectNormalizer(propertyTypeExtractor: new ReflectionExtractor()),
        ], [new JsonEncoder()]));
        $encoded = $serializer->encode($stored);
        self::assertArrayHasKey('headers', $encoded);
        self::assertStringNotContainsString(self::CANARY, json_encode($encoded['headers'] ?? [], JSON_THROW_ON_ERROR));
        self::assertStringContainsString(self::CANARY, $encoded['body']);
        $decoded = $serializer->decode($encoded);
        self::assertInstanceOf(DiagnosticCanaryEvent::class, $decoded->getMessage());
        self::assertSame('#0 {main}', $decoded->last(ErrorDetailsStamp::class)?->getFlattenException()?->getTraceAsString());

        $state->fail = false;
        $result = $bus->dispatch($decoded);
        self::assertCount(2, $result->all(HandledStamp::class));
        self::assertSame(1, $calls, 'Native HandledStamp skips the already-successful listener.');
        foreach ($logs->getRecords() as $record) {
            self::assertSame('messenger.activity', $record->message);
            self::assertStringNotContainsString(self::CANARY, json_encode($record->context, JSON_THROW_ON_ERROR));
        }
    }

    public function testLoggerNeverStringifiesArbitraryMessageOrContext(): void
    {
        $logs = new TestHandler();
        $logger = new SafeMessengerLogger(new Logger('messenger', [$logs]));
        $message = new class implements \Stringable {
            public function __toString(): string
            {
                throw new \LogicException('Must not stringify');
            }
        };
        $logger->error($message, ['exception' => new \RuntimeException(self::CANARY), 'message_id' => self::CANARY, 'delay' => 1000, 'payload' => $message]);
        self::assertSame(['delay' => 1000], $logs->getRecords()[0]->context);
    }

    public function testVerboseOperatorsHidePayloadAndMalformedDataButRetryExecutesInline(): void
    {
        $receiver = new DiagnosticListableTransport();
        $envelope = $receiver->send(new Envelope(new DiagnosticCanaryEvent(self::CANARY), [
            ErrorDetailsStamp::create(new \RuntimeException(self::CANARY)), new SentToFailureTransportStamp('events'),
        ]));
        $malformed = $receiver->send(MessageDecodingFailedException::wrap(['body' => self::CANARY, 'headers' => []], self::CANARY));
        /** @var ServiceLocator<ReceiverInterface> $locator */
        $locator = new ServiceLocator(['events_failed' => static fn () => $receiver]);
        $show = new CommandTester(new SafeFailedMessagesShowCommand('events_failed', $locator));
        foreach ([[], ['id' => '1'], ['id' => '2'], ['--stats' => true]] as $input) {
            self::assertSame(0, $show->execute($input, ['verbosity' => OutputInterface::VERBOSITY_DEBUG]));
            self::assertStringNotContainsString(self::CANARY, $show->getDisplay());
        }

        $calls = 0;
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            DiagnosticCanaryEvent::class => [static function () use (&$calls): void {
                ++$calls;
            }],
        ]))]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ConsoleErrorSubscriber(new InvocationContext()));
        $retry = new CommandTester(new SafeFailedMessagesRetryCommand('events_failed', $locator, $bus, $dispatcher));
        self::assertSame(0, $retry->execute(['id' => ['1'], '--force' => true], ['verbosity' => OutputInterface::VERBOSITY_DEBUG]));
        self::assertSame(1, $calls);
        self::assertStringNotContainsString(self::CANARY, $retry->getDisplay());
        self::assertNull($receiver->find($envelope->last(TransportMessageIdStamp::class)?->getId()));

        $remove = new CommandTester(new SafeFailedMessagesRemoveCommand('events_failed', $locator));
        self::assertSame(0, $remove->execute(['id' => ['2'], '--force' => true, '--show-messages' => true], ['verbosity' => OutputInterface::VERBOSITY_DEBUG]));
        self::assertStringNotContainsString(self::CANARY, $remove->getDisplay());
        self::assertNull($receiver->find($malformed->last(TransportMessageIdStamp::class)?->getId()));
    }

    public function testConsoleFailureIsSafeEvenAtDebugVerbosity(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ConsoleErrorSubscriber(new InvocationContext()));
        $app = new Application();
        $app->setAutoExit(false);
        $app->setDispatcher($dispatcher);
        $app->addCommand(new class('messenger:consume') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                throw new \RuntimeException('credential-payload-canary-17', 7, new \RuntimeException('nested-secret'));
            }
        });
        $tester = new ApplicationTester($app);
        self::assertSame(7, $tester->run(['command' => 'messenger:consume'], ['verbosity' => OutputInterface::VERBOSITY_DEBUG]));
        self::assertStringContainsString('details suppressed', $tester->getDisplay());
        self::assertStringNotContainsString(self::CANARY, $tester->getDisplay());
        self::assertStringNotContainsString('nested-secret', $tester->getDisplay());
    }

    public function testPoisonedWorkerStopsBeforeNextMessageAndRemainsUnusableAfterReset(): void
    {
        $context = new InvocationContext();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ConsoleErrorSubscriber($context));
        $receiver = new InMemoryTransport();
        $receiver->send(new Envelope(new DiagnosticCanaryEvent('first')));
        $receiver->send(new Envelope(new DiagnosticCanaryEvent('second')));
        $calls = 0;
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            DiagnosticCanaryEvent::class => [static function () use ($context, &$calls): void {
                ++$calls;
                $context->markUnusable();
            }],
        ]))]);
        (new Worker(['events' => $receiver], $bus, $dispatcher))->run(['sleep' => 0]);
        self::assertSame(1, $calls);
        self::assertCount(1, $receiver->getAcknowledged());
        $context->reset();
        self::assertFalse($context->isUsable());
        $this->expectException(\LogicException::class);
        (new Worker(['events' => $receiver], $bus, $dispatcher))->run(['sleep' => 0]);
    }
}

final readonly class DiagnosticCanaryEvent extends ApplicationEvent
{
    public function __construct(public string $payload)
    {
    }
}

final class DiagnosticListableTransport extends InMemoryTransport implements ListableReceiverInterface
{
    public function all(?int $limit = null): iterable
    {
        return $this->get($limit ?? PHP_INT_MAX);
    }

    public function find(mixed $id): ?Envelope
    {
        if (!is_string($id) && !is_int($id)) {
            return null;
        }
        foreach ($this->all() as $envelope) {
            $messageId = $envelope->last(TransportMessageIdStamp::class)?->getId();
            if ((is_string($messageId) || is_int($messageId)) && (string) $messageId === (string) $id) {
                return $envelope;
            }
        }

        return null;
    }
}
