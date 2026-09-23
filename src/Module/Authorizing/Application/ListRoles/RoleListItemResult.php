<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListRoles;

final readonly class RoleListItemResult
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $key,
        public string $label,
        public int $revision,
        public ?\DateTimeImmutable $retiredAt,
        public array $permissions,
    ) {
    }
}
