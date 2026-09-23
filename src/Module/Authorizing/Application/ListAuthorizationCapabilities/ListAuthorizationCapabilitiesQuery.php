<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListAuthorizationCapabilities;

final readonly class ListAuthorizationCapabilitiesQuery
{
    public function __construct(public int $limit = 50, public ?string $afterKey = null)
    {
    }
}
