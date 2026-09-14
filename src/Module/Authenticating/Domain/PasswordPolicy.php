<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Domain;

final class PasswordPolicy
{
    public static function validate(#[\SensitiveParameter] string $password): void
    {
        if (strlen($password) > 4096 || !mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0") || str_contains($password, "\r") || str_contains($password, "\n") || mb_strlen($password, 'UTF-8') < 15) {
            throw new InvalidPassword();
        }
    }
}
