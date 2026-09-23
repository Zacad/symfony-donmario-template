<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain;

final class InvalidAuthorizationInputException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Invalid authorization input.');
    }
}
