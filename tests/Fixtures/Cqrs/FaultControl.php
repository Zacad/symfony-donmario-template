<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Cqrs;

use App\Module\TaskTracking\Domain\Task;

/** In-process verification only; the HTTP application's kernel never registers it. */
final class FaultControl
{
    /** @var (\Closure(Task): void)|null */
    public ?\Closure $afterAdd = null;
    /** @var (\Closure(?Task): void)|null */
    public ?\Closure $afterFind = null;
}
