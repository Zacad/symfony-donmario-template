<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\EventCompilation;

use Psr\Log\AbstractLogger;

final class RuntimeLog extends AbstractLogger
{
    /** @var list<array{string, array<mixed>}> */
    public array $entries = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->entries[] = [(string) $message, $context];
    }
}
