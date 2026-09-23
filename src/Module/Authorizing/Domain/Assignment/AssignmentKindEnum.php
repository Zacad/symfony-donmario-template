<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Assignment;

enum AssignmentKindEnum: string
{
    case Role = 'role';
    case Permission = 'permission';
}
