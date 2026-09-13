<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Diagnostics;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Messenger\Command\FailedMessagesRetryCommand;

#[AsCommand(name: 'messenger:failed:retry', description: 'Retry failed messages using native Messenger')]
final class SafeFailedMessagesRetryCommand extends FailedMessagesRetryCommand
{
    use MetadataOnlyDisplay;
}
