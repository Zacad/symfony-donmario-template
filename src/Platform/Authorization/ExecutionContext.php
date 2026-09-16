<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

use App\Platform\Messaging\InvocationContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/** Infrastructure-owned scopes. Modules receive only immutable PolicyContext values. */
final class ExecutionContext
{
    /** @var list<class-string> */
    private array $messages = [];
    private ?Actor $override = null;
    private ?Actor $actor = null;

    public function __construct(private readonly InvocationContext $invocation, private readonly RequestStack $requests, private readonly TokenStorageInterface $tokens, private readonly AuthenticationTrustResolverInterface $trust)
    {
    }

    /** @param class-string $message */
    public function enter(string $message): PolicyContext
    {
        $caller = [] === $this->messages ? null : $this->messages[array_key_last($this->messages)];
        if ([] === $this->messages) {
            $this->actor = $this->override ?? $this->httpActor();
        }
        $actor = $this->actor ?? throw new \LogicException('authorization.context: Missing invocation identity.');
        $this->messages[] = $message;

        return new PolicyContext($actor, $this->invocation->inPolicy(), $caller);
    }

    public function leave(): void
    {
        array_pop($this->messages);
        if ([] === $this->messages) {
            $this->actor = null;
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(Actor $actor, callable $operation): mixed
    {
        if (!$this->invocation->isRoot() || [] !== $this->messages || null !== $this->override) {
            $failure = new \LogicException('authorization.context: Authority cannot change during execution.');
            $this->invocation->fail($failure);
            throw $failure;
        }
        $this->override = $actor;
        try {
            return $operation();
        } finally {
            $this->override = null;
        }
    }

    private function httpActor(): Actor
    {
        // A worker/console process must never inherit authority from stale token storage.
        if (null === $this->requests->getMainRequest()) {
            return new Actor(ActorKind::Anonymous);
        }
        $token = $this->tokens->getToken();
        if (!$this->trust->isFullFledged($token)) {
            return new Actor(ActorKind::Anonymous);
        }
        $identifier = $token?->getUserIdentifier();
        if (null === $identifier || !Uuid::isValid($identifier)) {
            return new Actor(ActorKind::Anonymous);
        }

        return new Actor(ActorKind::Account, Uuid::fromString($identifier));
    }
}
