<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListAuthorizationCapabilities;

final readonly class AuthorizationCapabilityResult
{
    /** @param list<string> $operations */
    public function __construct(
        public string $module,
        public string $key,
        public string $label,
        public string $access,
        public array $operations,
    ) {
    }
}
