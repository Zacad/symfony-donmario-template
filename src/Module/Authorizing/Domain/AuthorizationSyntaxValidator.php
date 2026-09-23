<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain;

final class AuthorizationSyntaxValidator
{
    public static function key(string $key): void
    {
        if (1 !== preg_match('/\A[a-z0-9._-]{1,64}\z/D', $key)) {
            throw new InvalidAuthorizationInputException();
        }
    }

    public static function label(string $label): void
    {
        if (!mb_check_encoding($label, 'UTF-8') || '' === trim($label) || mb_strlen($label, 'UTF-8') > 100 || str_contains($label, "\0") || str_contains($label, "\n") || str_contains($label, "\r")) {
            throw new InvalidAuthorizationInputException();
        }
    }
}
