<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use App\Platform\Architecture\ContractTypes;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ResultValidationMiddleware implements MiddlewareInterface
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $handled = $stack->next()->handle($envelope, $stack);
        $result = $handled->last(HandledStamp::class)?->getResult();
        if (\is_object($result) && !$result instanceof \UnitEnum && ContractTypes::isPublic($result::class)) {
            try {
                $invalid = 0 !== \count($this->validator->validate($result));
            } catch (\Throwable) {
                // Do not retain validator exceptions, violations or the returned DTO.
                throw new \LogicException('cqrs.result_validation: Handler returned invalid data.');
            }
            if ($invalid) {
                throw new \LogicException('cqrs.result_validation: Handler returned invalid data.');
            }
        }

        return $handled;
    }
}
