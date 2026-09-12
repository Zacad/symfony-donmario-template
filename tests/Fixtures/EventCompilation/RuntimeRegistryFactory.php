<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\EventCompilation;

use Doctrine\Bundle\DoctrineBundle\Registry;

final class RuntimeRegistryFactory
{
    public static ?Registry $registry = null;

    public static function create(): Registry
    {
        return self::$registry ?? throw new \LogicException('The offline registry double must be supplied before boot.');
    }
}
