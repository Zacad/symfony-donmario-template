<?php

declare(strict_types=1);

namespace App\Module\Authenticating\UI\Console;

use App\Module\Authenticating\Application\RegisterAccount\RegisterAccountCommand;
use App\Module\Authenticating\Domain\InvalidEmailAddress;
use App\Module\Authenticating\Domain\InvalidPassword;
use App\Platform\Authorization\OperatorExecution;
use App\Platform\Messaging\CommandBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:account:provision', description: 'Provision an account using a hidden password prompt or explicit standard input.')]
final class ProvisionAccountConsoleCommand extends Command
{
    public function __construct(private readonly CommandBus $commands, private readonly LoggerInterface $logger, private readonly OperatorExecution $operator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Account email address');
        $this->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read one password from standard input, preserving whitespace.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $email = $input->getArgument('email');
            if (!is_string($email)) {
                throw new InvalidEmailAddress();
            }
            $password = $this->readPassword($input, $output);
            $message = new RegisterAccountCommand($email, $password);
            $id = $this->operator->run('accounts', fn (): mixed => $this->commands->dispatch($message));
            if (!$id instanceof Uuid) {
                throw new \LogicException('Unexpected account registration result.');
            }
            $output->writeln($id->toRfc4122(), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        } catch (InvalidEmailAddress|InvalidPassword|ValidationFailedException) {
            $output->writeln('Invalid account input. Use a valid ASCII email and a password of at least 15 Unicode characters and at most 4096 bytes, without NUL or line breaks. Hidden confirmation requires an interactive terminal; use --password-stdin for explicit standard input.', OutputInterface::OUTPUT_RAW);

            return Command::INVALID;
        } catch (\Throwable) {
            // Never pass the exception, account identifier or credentials to diagnostics, even at -vvv.
            try {
                $this->logger->error('Account provisioning failed.', ['operation' => 'provision_account']);
            } catch (\Throwable) {
                // Logging cannot replace the redacted command result.
            }
            $output->writeln('Account provisioning failed.', OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        } finally {
            unset($password);
        }
    }

    private function readPassword(InputInterface $input, OutputInterface $output): string
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $stream ??= STDIN;
        if ($input->getOption('password-stdin')) {
            // One extra byte detects overflow without consuming an unbounded stream.
            $password = stream_get_contents($stream, 4099);
            if (false === $password || strlen($password) > 4098) {
                throw new InvalidPassword();
            }

            return $this->removeLineEnding($password);
        }
        if (!$input->isInteractive() || !stream_isatty($stream)) {
            throw new InvalidPassword();
        }

        try {
            $helper = $this->getHelper('question');
            if (!$helper instanceof QuestionHelper) {
                throw new InvalidPassword();
            }
            $password = $helper->ask($input, $output, $this->passwordQuestion('Password: '));
            $confirmation = $helper->ask($input, $output, $this->passwordQuestion('Confirm password: '));
            if (!is_string($password) || !is_string($confirmation)) {
                throw new InvalidPassword();
            }
            // Symfony's non-trimmable hidden reader retains the submitted line ending.
            $password = $this->removeLineEnding($password);
            $confirmation = $this->removeLineEnding($confirmation);
            if (!hash_equals($password, $confirmation)) {
                throw new InvalidPassword();
            }

            return $password;
        } catch (\Throwable) {
            throw new InvalidPassword();
        }
    }

    private function removeLineEnding(#[\SensitiveParameter] string $password): string
    {
        if (str_ends_with($password, "\r\n")) {
            return substr($password, 0, -2);
        }
        if (str_ends_with($password, "\n")) {
            return substr($password, 0, -1);
        }

        return $password;
    }

    private function passwordQuestion(string $prompt): Question
    {
        $question = new Question($prompt);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setTrimmable(false);

        return $question;
    }
}
