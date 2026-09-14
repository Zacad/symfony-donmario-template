<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Domain;

interface PasswordHasher
{
    public function hash(#[\SensitiveParameter] string $password): string;
}
