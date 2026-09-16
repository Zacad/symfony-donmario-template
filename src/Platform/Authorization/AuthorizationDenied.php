<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

final class AuthorizationDenied extends \RuntimeException
{
    public function __construct(public readonly bool $authenticated)
    {
        parent::__construct('Access denied.');
    }
}
