<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Symfony\Security;

use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceQuery;
use App\Module\Authenticating\Application\GetAccountIdentity\GetAccountIdentityQuery;
use App\Module\Authenticating\Application\RegisterAccount\RegisterAccountCommand;
use App\Module\Authenticating\Application\UpgradePasswordHash\UpgradePasswordHashCommand;
use App\Platform\Authorization\ActorKind;
use App\Platform\Authorization\AuthorizationToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, object> */
final class AuthenticatingVoter extends Voter
{
    /** @param array<class-string, ?string> $permissions */
    public function __construct(private readonly array $permissions)
    {
    }

    public function supportsAttribute(string $attribute): bool
    {
        return self::class === $attribute;
    }

    public function supportsType(string $subjectType): bool
    {
        return array_key_exists($subjectType, $this->permissions);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::class === $attribute && is_object($subject) && array_key_exists($subject::class, $this->permissions);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$token instanceof AuthorizationToken) {
            return false;
        }
        $actor = $token->actor;

        return match (true) {
            $subject instanceof RegisterAccountCommand => $actor->isOperator('accounts'),
            $subject instanceof UpgradePasswordHashCommand => ActorKind::Authentication === $actor->kind
                && null !== $actor->accountId && $actor->accountId->equals($subject->accountId),
            $subject instanceof GetAccountIdentityQuery => $actor->isAccount()
                && null !== $actor->accountId && $actor->accountId->equals($subject->id),
            $subject instanceof CheckAccountExistenceQuery => $token->supportRead,
            default => false,
        };
    }
}
