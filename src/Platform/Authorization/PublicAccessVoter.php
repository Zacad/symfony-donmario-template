<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, object> */
final class PublicAccessVoter extends Voter
{
    /** @param array<class-string, null> $messages */
    public function __construct(private readonly array $messages)
    {
    }

    public function supportsAttribute(string $attribute): bool
    {
        return self::class === $attribute;
    }

    public function supportsType(string $subjectType): bool
    {
        return array_key_exists($subjectType, $this->messages);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::class === $attribute && is_object($subject) && array_key_exists($subject::class, $this->messages);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $token instanceof AuthorizationToken;
    }
}
