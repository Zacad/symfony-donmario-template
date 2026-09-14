<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Domain;

final class InvalidEmailAddress extends \InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Email must be a valid ASCII address of at most 254 bytes.');
    }
}
