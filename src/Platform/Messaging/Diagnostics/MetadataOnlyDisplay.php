<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Diagnostics;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/** Only presentation changes; native retry/remove execution is inherited. */
trait MetadataOnlyDisplay
{
    protected function displaySingleMessage(Envelope $envelope, SymfonyStyle $io, ?SymfonyStyle $errorIo = null): void
    {
        $io->table(['Id', 'Class', 'Retries'], [[
            $envelope->last(TransportMessageIdStamp::class)?->getId(),
            $envelope->getMessage()::class,
            RedeliveryStamp::getRetryCountFromEnvelope($envelope),
        ]]);
    }
}
