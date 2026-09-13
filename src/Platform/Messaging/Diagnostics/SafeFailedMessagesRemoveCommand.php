<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Diagnostics;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Messenger\Command\FailedMessagesRemoveCommand;

#[AsCommand(name: 'messenger:failed:remove', description: 'Remove failed messages using native Messenger')]
final class SafeFailedMessagesRemoveCommand extends FailedMessagesRemoveCommand
{
    use MetadataOnlyDisplay;
}
