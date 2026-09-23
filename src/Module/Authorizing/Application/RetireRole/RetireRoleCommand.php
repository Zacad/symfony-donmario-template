<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\RetireRole;

final readonly class RetireRoleCommand
{
    public function __construct(public string $key, public int $expectedRevision)
    {
    }
}
