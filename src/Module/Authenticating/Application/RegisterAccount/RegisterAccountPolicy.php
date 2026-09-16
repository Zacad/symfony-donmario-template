<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\RegisterAccount;

use App\Platform\Authorization\PolicyContext;

final readonly class RegisterAccountPolicy
{
    public function __invoke(RegisterAccountCommand $message, PolicyContext $context): bool
    {
        return $context->actor->isOperator('accounts');
    }
}
