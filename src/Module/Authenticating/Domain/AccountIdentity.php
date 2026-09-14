<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Domain;

use Symfony\Component\Uid\Uuid;

final readonly class AccountIdentity
{
    public function __construct(public Uuid $id, public string $email)
    {
    }
}
