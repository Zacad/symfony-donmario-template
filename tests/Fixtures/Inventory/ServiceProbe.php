<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Inventory;

/** A stable test-side type for observing uniquely namespaced generated services. */
interface ServiceProbe
{
    public function value(): string;
}
