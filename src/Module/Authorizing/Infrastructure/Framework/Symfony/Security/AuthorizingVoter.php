<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Infrastructure\Framework\Symfony\Security;

use App\Module\Authorizing\Application\ChangeSubjectAssignments\ChangeSubjectAssignmentsCommand;
use App\Module\Authorizing\Application\DefineRole\DefineRoleCommand;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EntitlementCheckInput;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsQuery;
use App\Module\Authorizing\Application\EvaluateSubjectEntitlements\EvaluateSubjectEntitlementsResult;
use App\Module\Authorizing\Application\GetRole\GetRoleQuery;
use App\Module\Authorizing\Application\ListAuthorizationCapabilities\ListAuthorizationCapabilitiesQuery;
use App\Module\Authorizing\Application\ListRoles\ListRolesQuery;
use App\Module\Authorizing\Application\ListSubjectAssignments\ListSubjectAssignmentsQuery;
use App\Module\Authorizing\Application\RetireRole\RetireRoleCommand;
use App\Platform\Authorization\AuthorizationToken;
use App\Platform\Messaging\QueryBus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, object> */
final class AuthorizingVoter extends Voter
{
    /** @param array<class-string, ?string> $routes */
    public function __construct(private readonly array $routes, private readonly QueryBus $queries)
    {
    }

    public function supportsAttribute(string $attribute): bool
    {
        return self::class === $attribute;
    }

    public function supportsType(string $subjectType): bool
    {
        return array_key_exists($subjectType, $this->routes);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::class === $attribute && is_object($subject) && array_key_exists($subject::class, $this->routes);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$token instanceof AuthorizationToken) {
            return false;
        }
        if (!array_key_exists($subject::class, $this->routes)) {
            return false;
        }
        if ($subject instanceof EvaluateSubjectEntitlementsQuery) {
            return $token->supportRead || $token->actor->isOperator('assignments');
        }
        $permission = $this->routes[$subject::class];
        if (null === $permission) {
            return false;
        }
        if ($subject instanceof ChangeSubjectAssignmentsCommand || $subject instanceof ListSubjectAssignmentsQuery) {
            return $token->actor->isOperator('assignments') || $this->hasPermission($token, $permission);
        }
        if ($subject instanceof DefineRoleCommand || $subject instanceof RetireRoleCommand
            || $subject instanceof ListRolesQuery || $subject instanceof GetRoleQuery
            || $subject instanceof ListAuthorizationCapabilitiesQuery) {
            return $token->actor->isOperator('catalogue') || $this->hasPermission($token, $permission);
        }

        return false;
    }

    private function hasPermission(AuthorizationToken $token, string $permission): bool
    {
        if (!$token->actor->isAccount() || null === $token->actor->accountId) {
            return false;
        }
        $result = $this->queries->ask(new EvaluateSubjectEntitlementsQuery([
            new EntitlementCheckInput($token->actor->accountId, $permission),
        ]));

        return $result instanceof EvaluateSubjectEntitlementsResult && 1 === count($result->decisions) && $result->decisions[0]->allowed;
    }
}
