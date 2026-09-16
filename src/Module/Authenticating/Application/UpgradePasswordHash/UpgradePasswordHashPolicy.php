<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\UpgradePasswordHash;

use App\Platform\Authorization\ActorKind;
use App\Platform\Authorization\PolicyContext;

final readonly class UpgradePasswordHashPolicy
{
    public function __invoke(UpgradePasswordHashCommand $message, PolicyContext $context): bool
    {
        return ActorKind::Authentication === $context->actor->kind
            && null !== $context->actor->accountId
            && $context->actor->accountId->equals($message->accountId);
    }
}
