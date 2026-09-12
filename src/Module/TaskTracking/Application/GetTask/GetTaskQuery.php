<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\GetTask;

final readonly class GetTaskQuery
{
    public function __construct(public string $id)
    {
    }
}
