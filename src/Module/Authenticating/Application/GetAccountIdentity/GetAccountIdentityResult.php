<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\GetAccountIdentity;

use Symfony\Component\Uid\Uuid;

final readonly class GetAccountIdentityResult
{
    public function __construct(public Uuid $id, public string $email)
    {
    }
}
