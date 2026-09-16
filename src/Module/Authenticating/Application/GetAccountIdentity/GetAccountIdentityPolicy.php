<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\GetAccountIdentity;

use App\Platform\Authorization\PolicyContext;

final readonly class GetAccountIdentityPolicy
{
    public function __invoke(GetAccountIdentityQuery $message, PolicyContext $context): bool
    {
        return $context->actor->isAccount()
            && null !== $context->actor->accountId
            && $context->actor->accountId->equals($message->id);
    }
}
