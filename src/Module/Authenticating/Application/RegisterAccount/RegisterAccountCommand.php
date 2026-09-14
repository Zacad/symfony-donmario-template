<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\RegisterAccount;

final readonly class RegisterAccountCommand
{
    public function __construct(public string $email, public string $password)
    {
    }
}
