<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\DefineRole;

final readonly class DefineRoleCommand
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $key,
        public string $label,
        public array $permissions,
        public ?int $expectedRevision = null,
        public bool $createIfAbsent = false,
    ) {
    }
}
