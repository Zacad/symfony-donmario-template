<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain\Assignment;

enum AssignmentOperationEnum: string
{
    case Add = 'add';
    case Remove = 'remove';
}
