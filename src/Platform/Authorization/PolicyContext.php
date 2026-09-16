<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

/** Read-only facts about this invocation; constructing this value grants no bus authority. */
final readonly class PolicyContext
{
    public function __construct(public Actor $actor, public bool $supportRead, public ?string $caller)
    {
    }
}
