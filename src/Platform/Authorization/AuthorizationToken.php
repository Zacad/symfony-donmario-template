<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Credential-free bridge used only by the isolated authorization decision manager. */
final class AuthorizationToken implements TokenInterface
{
    public function __construct(
        public readonly Actor $actor,
        public readonly bool $supportRead,
    ) {
    }

    public function __toString(): string
    {
        return 'authorization-token';
    }

    public function getUserIdentifier(): string
    {
        return '';
    }

    public function getRoleNames(): array
    {
        return [];
    }

    public function getUser(): ?UserInterface
    {
        return null;
    }

    public function setUser(UserInterface $user): never
    {
        throw new \LogicException('authorization.token: Immutable token.');
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return [];
    }

    /** @param array<string, mixed> $attributes */
    public function setAttributes(array $attributes): never
    {
        throw new \LogicException('authorization.token: Immutable token.');
    }

    public function hasAttribute(string $name): bool
    {
        return false;
    }

    public function getAttribute(string $name): never
    {
        throw new \InvalidArgumentException('authorization.token: Unknown attribute.');
    }

    public function setAttribute(string $name, mixed $value): never
    {
        throw new \LogicException('authorization.token: Immutable token.');
    }

    public function __serialize(): array
    {
        throw new \LogicException('authorization.token: Serialization is forbidden.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): never
    {
        throw new \LogicException('authorization.token: Serialization is forbidden.');
    }
}
