<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class MessagePolicyMiddleware implements MiddlewareInterface
{
    /** @param array<class-string, string> $messages The compiled message inventory. */
    public function __construct(private string $kind, private array $messages)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ([] !== $envelope->all() || ($this->messages[$envelope->getMessage()::class] ?? null) !== $this->kind) {
            throw new \LogicException('cqrs.message: dispatch a known '.$this->kind.' DTO without envelopes or stamps.');
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
