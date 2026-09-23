<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Authorize
{
    /** @param class-string|null $voter */
    public function __construct(
        public ?string $voter = null,
        public ?\BackedEnum $permission = null,
        public ?string $label = null,
        public bool $public = false,
    ) {
    }
}
