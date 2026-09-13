<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Diagnostics;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Command\FailedMessagesShowCommand;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

#[AsCommand(name: 'messenger:failed:show', description: 'Show failed-message metadata')]
final class SafeFailedMessagesShowCommand extends FailedMessagesShowCommand
{
    use MetadataOnlyDisplay;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (null !== $input->getArgument('id') && !$input->getOption('stats')) {
            return parent::execute($input, $output);
        }

        // Native listMessages() is private and prints arbitrary error messages.
        $io = new SymfonyStyle($input, $output);
        $name = $input->getOption('transport');
        if (self::DEFAULT_TRANSPORT_OPTION === $name) {
            $name = $this->getGlobalFailureReceiverName();
        } elseif ('' === $name || null === $name) {
            $name = $this->interactiveChooseFailureTransport($io);
        }
        if (null !== $name && !is_string($name)) {
            throw new \InvalidArgumentException('messenger.invalid_transport');
        }
        $receiver = $this->getReceiver($name);
        if (!$receiver instanceof ListableReceiverInterface) {
            throw new \RuntimeException('messenger.receiver_not_listable');
        }

        $this->printPendingMessagesMessage($receiver, $io);
        $stats = $input->getOption('stats');
        $max = filter_var($input->getOption('max'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $max) {
            throw new \InvalidArgumentException('messenger.invalid_limit');
        }
        $max = $stats && !$input->hasParameterOption('--max', true) ? null : $max;
        $rows = $counts = [];
        foreach ($receiver->all($max) as $envelope) {
            $class = $envelope->getMessage()::class;
            if ($stats) {
                $counts[$class] = ($counts[$class] ?? 0) + 1;
            } elseif (!$input->getOption('class-filter') || $class === $input->getOption('class-filter')) {
                $rows[] = [$this->getMessageId($envelope), $class, RedeliveryStamp::getRetryCountFromEnvelope($envelope)];
            }
        }
        if ($stats) {
            foreach ($counts as $class => $count) {
                $rows[] = [$class, $count];
            }
        }
        $io->table($stats ? ['Class', 'Count'] : ['Id', 'Class', 'Retries'], $rows);

        return self::SUCCESS;
    }
}
