<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\UpgradePasswordHash;

use Symfony\Component\Uid\Uuid;

final readonly class UpgradePasswordHashCommand
{
    public function __construct(public Uuid $accountId, public string $expectedPasswordHash, public string $newPasswordHash)
    {
    }
}
