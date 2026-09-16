<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\CheckAccountExistence;

use Symfony\Component\Uid\Uuid;

final readonly class CheckAccountExistenceQuery
{
    /** @param list<Uuid> $accountIds */
    public function __construct(public array $accountIds)
    {
    }
}
