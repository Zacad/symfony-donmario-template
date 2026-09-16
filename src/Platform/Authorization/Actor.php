<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

use Symfony\Component\Uid\Uuid;

/** Trusted execution identity, supplied by infrastructure, never message input. */
final readonly class Actor
{
    public function __construct(public ActorKind $kind, public ?Uuid $accountId = null, public ?string $scope = null)
    {
    }

    public function isAccount(): bool
    {
        return ActorKind::Account === $this->kind && null !== $this->accountId;
    }

    public function isOperator(string $scope): bool
    {
        return ActorKind::Operator === $this->kind && $scope === $this->scope;
    }
}
