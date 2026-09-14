<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\GetAccountIdentity;

use Symfony\Component\Uid\Uuid;

final readonly class GetAccountIdentityQuery
{
    public function __construct(public Uuid $id)
    {
    }
}
