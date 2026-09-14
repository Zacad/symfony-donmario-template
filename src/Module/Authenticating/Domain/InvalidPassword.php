<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Domain;

final class InvalidPassword extends \InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Password must contain at least 15 Unicode characters and at most 4096 bytes, with valid UTF-8 and no NUL or line breaks.');
    }
}
