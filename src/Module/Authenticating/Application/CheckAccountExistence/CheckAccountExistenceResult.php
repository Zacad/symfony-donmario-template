<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\CheckAccountExistence;

final readonly class CheckAccountExistenceResult
{
    /** @param list<AccountExistenceResult> $accounts */
    public function __construct(public array $accounts)
    {
    }
}
