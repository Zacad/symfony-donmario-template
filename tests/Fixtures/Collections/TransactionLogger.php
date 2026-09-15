<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Collections;

use Psr\Log\AbstractLogger;

/** Records native driver transaction calls, never SQL, parameters or connection data. */
final class TransactionLogger extends AbstractLogger
{
    public function __construct(private readonly string $fixtureRoot)
    {
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        if (\in_array($message, ['Beginning transaction', 'Committing transaction', 'Rolling back transaction'], true)) {
            file_put_contents($this->fixtureRoot.'/transactions.txt', $message."\n", FILE_APPEND | LOCK_EX);
        }
    }
}
