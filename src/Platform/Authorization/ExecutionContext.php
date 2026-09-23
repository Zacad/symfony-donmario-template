<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

use App\Platform\Messaging\InvocationContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/** Infrastructure-owned scopes. Voters receive only an explicit credential-free token. */
final class ExecutionContext
{
    private int $depth = 0;
    private ?Actor $override = null;
    private ?Actor $actor = null;

    public function __construct(private readonly InvocationContext $invocation, private readonly RequestStack $requests, private readonly TokenStorageInterface $tokens, private readonly AuthenticationTrustResolverInterface $trust)
    {
    }

    public function enter(): AuthorizationToken
    {
        $this->invocation->assertHealthy();
        if (0 === $this->depth) {
            $this->actor = $this->override ?? $this->httpActor();
        }
        $actor = $this->actor ?? throw new \LogicException('authorization.context: Missing invocation identity.');
        ++$this->depth;

        return new AuthorizationToken($actor, $this->invocation->inAuthorizationDecision());
    }

    public function leave(): void
    {
        if ($this->depth < 1) {
            throw new \LogicException('authorization.context: Execution frame underflow.');
        }
        --$this->depth;
        if (0 === $this->depth) {
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
        if (!$this->invocation->isRoot() || 0 !== $this->depth || null !== $this->override) {
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
