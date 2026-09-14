<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Domain;

final class PasswordHash
{
    public static function validate(#[\SensitiveParameter] string $hash): void
    {
        // Syntax checks only: never perform attacker-selected hashing work to validate stored data.
        if (strlen($hash) > 255 || 1 !== preg_match('/\A(?:\$2[aby]\$(?:0[4-9]|[12][0-9]|3[01])\$[.\/A-Za-z0-9]{53}|\$argon2(?:id|i)\$v=19\$m=[1-9][0-9]{0,8},t=[1-9][0-9]{0,8},p=[1-9][0-9]{0,8}\$[A-Za-z0-9+\/]{16,86}\$[A-Za-z0-9+\/]{32,86})\z/D', $hash)) {
            throw new InvalidPasswordHash();
        }
    }
}
