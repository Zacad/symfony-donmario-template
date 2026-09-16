<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\Authenticating\Application\RegisterAccount\RegisterAccountCommand;
use App\Module\Authenticating\Application\UpgradePasswordHash\UpgradePasswordHashCommand;
use App\Platform\Authorization\Actor;
use App\Platform\Authorization\ActorKind;
use App\Platform\Authorization\ExecutionContext;
use App\Platform\Messaging\CommandBus;
use App\Tests\Fixtures\Authenticating\AuthenticatingKernel;
use App\Tests\Fixtures\Authenticating\Browser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

abstract class AuthenticatingTestCase extends DatabaseTestCase
{
    protected Connection $connection;
    protected string $prefix;

    protected static function getKernelClass(): string
    {
        return AuthenticatingKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->database();
        $this->prefix = 'auth-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DELETE FROM public.authenticating_account WHERE email LIKE ?', [$this->prefix.'%']);
            $this->connection->close();
        }
        parent::tearDown();
    }

    protected function commands(): CommandBus
    {
        $commands = self::getContainer()->get('test.authenticating.commands');
        self::assertInstanceOf(CommandBus::class, $commands);

        return $commands;
    }

    /** Root fixture operations only; nested calls use commands() to retain their actor. */
    protected function dispatch(#[\SensitiveParameter] object $command): mixed
    {
        $actor = match ($command::class) {
            RegisterAccountCommand::class => new Actor(ActorKind::Operator, scope: 'accounts'),
            UpgradePasswordHashCommand::class => new Actor(ActorKind::Authentication, $command->accountId),
            default => throw new \LogicException('Unsupported authenticating fixture command.'),
        };
        $execution = self::getContainer()->get(ExecutionContext::class);
        self::assertInstanceOf(ExecutionContext::class, $execution);

        return $execution->run($actor, fn (): mixed => $this->commands()->dispatch($command));
    }

    /** @return array{id: string, email: string, password: string, hash: string} */
    protected function account(string $suffix = '', ?string $password = null): array
    {
        $password ??= Browser::secret();
        $email = $this->prefix.$suffix.'@example.test';
        $id = $this->dispatch(new RegisterAccountCommand($email, $password));
        self::assertInstanceOf(Uuid::class, $id);
        // Seed legacy storage only after ordinary registration has validated and
        // hashed its plaintext input. Native web login must upgrade this fixture.
        $currentHash = $this->storedHash($id->toRfc4122());
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertTrue($this->dispatch(new UpgradePasswordHashCommand($id, $currentHash, $hash)));

        return ['id' => $id->toRfc4122(), 'email' => $email, 'password' => $password, 'hash' => $hash];
    }

    /** @param list<string> $options */
    protected function provision(string $email, #[\SensitiveParameter] string $input, array $options = ['--password-stdin', '--no-interaction']): Process
    {
        $process = new Process(['php', 'bin/console', 'app:account:provision', $email, ...$options], dirname(__DIR__, 2), timeout: 30);
        $process->setInput($input);
        $process->run();

        return $process;
    }

    protected function storedHash(string $id): string
    {
        $hash = $this->connection->fetchOne('SELECT password_hash FROM public.authenticating_account WHERE id = ?', [$id]);
        self::assertIsString($hash);

        return $hash;
    }

    protected function safeOutput(Process $process, #[\SensitiveParameter] string $password): void
    {
        $output = $process->getOutput().$process->getErrorOutput();
        self::assertFalse(str_contains($output, $password), 'CLI exposed password input.');
        self::assertSame(0, preg_match('/\$2[aby]\$|\$argon2/', $output), 'CLI exposed a password hash.');
    }
}
