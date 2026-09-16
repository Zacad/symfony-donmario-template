<?php

declare(strict_types=1);

namespace App\Platform\Authorization;

enum ActorKind
{
    case Anonymous;
    case Account;
    case Operator;
    case Authentication;
}
