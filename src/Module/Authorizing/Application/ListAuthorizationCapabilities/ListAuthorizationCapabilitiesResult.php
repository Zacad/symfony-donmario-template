<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListAuthorizationCapabilities;

final readonly class ListAuthorizationCapabilitiesResult
{
    /** @param list<AuthorizationCapabilityResult> $capabilities */
    public function __construct(public array $capabilities, public ?string $nextAfterKey = null)
    {
    }
}
