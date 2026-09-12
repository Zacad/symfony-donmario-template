<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/** Result extraction is also performed inside the command's pre-commit callback. */
final class DispatchResult
{
    public static function from(Envelope $envelope): mixed
    {
        $handled = $envelope->all(HandledStamp::class);
        if (1 !== count($handled)) {
            throw new \LogicException('cqrs.result: exactly one synchronous handler result is required.');
        }

        return $handled[0]->getResult();
    }

    public static function cause(\Throwable $failure): \Throwable
    {
        while ($failure instanceof HandlerFailedException && 1 === count($failure->getWrappedExceptions())) {
            $failure = array_values($failure->getWrappedExceptions())[0];
        }

        return $failure;
    }
}
