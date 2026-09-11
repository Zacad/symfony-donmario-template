<?php

declare(strict_types=1);

use App\Tools\Architecture\DeptracRules;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;

return static function (DeptracConfig $config): void {
    DeptracRules::configure($config, __DIR__);
};
