<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Domain;

final class EmailAddress
{
    public static function normalize(string $email): string
    {
        $email = strtolower(trim($email, " \t\n\r\v\f"));
        if (strlen($email) > 254 || 1 !== preg_match('/\A[\x21-\x7e]+\z/D', $email) || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidEmailAddress();
        }

        return $email;
    }
}
