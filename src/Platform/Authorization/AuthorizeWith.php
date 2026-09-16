<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AuthorizeWith
{
    /** @param class-string $policy */
    public function __construct(public string $policy)
    {
    }
}
