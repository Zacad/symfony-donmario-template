<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Domain;

final class InvalidPasswordHash extends \InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Password hash must use a supported bounded format.');
    }
}
