<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\GetRole;

final readonly class GetRoleQuery
{
    public function __construct(public string $key)
    {
    }
}
