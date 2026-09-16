<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\CheckAccountExistence;

use Symfony\Component\Uid\Uuid;

final readonly class AccountExistenceResult
{
    public function __construct(public Uuid $accountId, public bool $exists)
    {
    }
}
