<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\TaskTracking\Application\GetTask\GetTaskResult;
use App\Platform\Messaging\ResultValidationMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Mapping\Loader\LoaderInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ResultValidationMiddlewareTest extends TestCase
{
    public function testNativeValidatorRejectsInvalidDtoWithoutExposingItsViolations(): void
    {
        $validator = Validation::createValidatorBuilder()->addLoader(new class implements LoaderInterface {
            public function loadClassMetadata(ClassMetadata $metadata): bool
            {
                if (GetTaskResult::class === $metadata->getClassName()) {
                    $metadata->addPropertyConstraint('title', new NotBlank(message: 'private-result-violation-canary'));
                }

                return true;
            }
        })->getValidator();
        $valid = new GetTaskResult(Uuid::v7(), 'valid');
        self::assertSame($valid, $this->dispatch($validator, static fn (): GetTaskResult => $valid)->last(HandledStamp::class)?->getResult());
        try {
            $this->dispatch($validator, static fn (): GetTaskResult => new GetTaskResult(Uuid::v7(), ''));
            self::fail('Expected internal result-validation failure.');
        } catch (\LogicException $failure) {
            self::assertSame('cqrs.result_validation: Handler returned invalid data.', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    public function testScalarNullValueEnumAndVoidResultsDoNotGoThroughObjectValidation(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::never())->method('validate');
        foreach (['value', 42, 1.5, false, null, Uuid::v7(), new \DateTimeImmutable(), \Random\IntervalBoundary::ClosedOpen] as $value) {
            self::assertSame($value, $this->dispatch($validator, static fn (): mixed => $value)->last(HandledStamp::class)?->getResult());
        }
        self::assertNull($this->dispatch($validator, static function (): void {})->last(HandledStamp::class)?->getResult());
    }

    public function testNativeValidatorFailureIsRedactedWithoutRetainingTheException(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::once())->method('validate')->willThrowException(new \RuntimeException('private-validator-payload-canary'));
        try {
            $this->dispatch($validator, static fn (): GetTaskResult => new GetTaskResult(Uuid::v7(), 'private-result-canary'));
            self::fail('Expected fixed internal failure.');
        } catch (\LogicException $failure) {
            self::assertSame('cqrs.result_validation: Handler returned invalid data.', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    /** @param callable(): mixed $handler */
    private function dispatch(ValidatorInterface $validator, callable $handler): Envelope
    {
        return new MessageBus([
            new ResultValidationMiddleware($validator),
            new HandleMessageMiddleware(new HandlersLocator([\stdClass::class => [$handler]])),
        ])->dispatch(new \stdClass());
    }
}
