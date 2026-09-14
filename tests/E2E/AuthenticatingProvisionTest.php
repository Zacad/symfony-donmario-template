<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Module\Authenticating\Application\RegisterAccount\RegisterAccountCommand;
use App\Module\Authenticating\Domain\InvalidEmailAddress;
use App\Module\Authenticating\Domain\InvalidPassword;
use App\Module\Authenticating\Domain\PasswordHasher;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\SymfonyPasswordHasher;
use App\Tests\Fixtures\Authenticating\Browser;
use App\Tests\Fixtures\Authenticating\FailingPasswordHasher;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

final class AuthenticatingProvisionTest extends AuthenticatingTestCase
{
    private const string INVALID_OUTPUT = 'Invalid account input. Use a valid ASCII email and a password of at least 15 Unicode characters and at most 4096 bytes, without NUL or line breaks. Hidden confirmation requires an interactive terminal; use --password-stdin for explicit standard input.'."\n";
    private const array DEBUG_STDIN = ['--password-stdin', '--no-interaction', '-vvv'];

    public function testPipePreservesSpacesAndCanonicalizesEmailWithoutOverwrite(): void
    {
        $password = '  '.Browser::secret().'  ';
        $email = $this->prefix.'@example.test';
        $process = $this->provision(strtoupper($email), $password."\r\n");
        self::assertSame(0, $process->getExitCode(), 'Provisioning failed.');
        $this->safeOutput($process, $password);
        $id = trim($process->getOutput());
        self::assertTrue(Uuid::isValid($id));
        $hash = $this->storedHash($id);
        self::assertTrue(password_verify($password, $hash));
        self::assertFalse(password_verify(trim($password), $hash));
        self::assertSame(13, password_get_info($hash)['options']['cost'] ?? null);
        self::assertSame($email, $this->connection->fetchOne('SELECT email FROM public.authenticating_account WHERE id = ?', [$id]));
        $duplicatePassword = Browser::secret();
        $duplicate = $this->provision($email, $duplicatePassword, self::DEBUG_STDIN);
        self::assertSame(1, $duplicate->getExitCode());
        $this->safeOutput($duplicate, $duplicatePassword);
        $this->safeOutput($duplicate, $password);
        self::assertTrue("Account provisioning failed.\n" === $duplicate->getOutput(), 'Duplicate failure must use fixed output at debug verbosity.');
        self::assertTrue(hash_equals($hash, $this->storedHash($id)), 'Duplicate provisioning overwrote the existing hash.');
        $second = $this->provision($this->prefix.'-salt@example.test', $password."\n");
        self::assertSame(0, $second->getExitCode());
        $secondHash = $this->storedHash(trim($second->getOutput()));
        self::assertTrue(password_verify($password, $secondHash));
        self::assertFalse(hash_equals($hash, $secondHash), 'Hashes must use independent salts.');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPasswords(): iterable
    {
        yield 'empty' => [''];
        yield '14 unicode characters' => [str_repeat('é', 14)];
        yield 'invalid UTF8' => [str_repeat('x', 15)."\xff"];
        yield 'NUL' => [str_repeat('x', 15)."\0"];
        yield 'embedded LF' => [str_repeat('x', 15)."\ninside"];
        yield 'embedded CR' => [str_repeat('x', 15)."\rinside"];
        yield 'two terminal lines' => [str_repeat('x', 15)."\n\n"];
        yield '4097 bytes' => [str_repeat('x', 4097)];
        yield 'bounded pipe' => [str_repeat('x', 65536)];
    }

    #[DataProvider('invalidPasswords')]
    public function testRejectsInvalidBoundedPipeInput(#[\SensitiveParameter] string $password): void
    {
        $process = $this->provision($this->prefix.'@example.test', $password, self::DEBUG_STDIN);
        self::assertSame(2, $process->getExitCode(), 'Invalid input must return validation status.');
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM public.authenticating_account WHERE email = ?', [$this->prefix.'@example.test']));
        if ('' !== $password) {
            $this->safeOutput($process, $password);
        }
        self::assertTrue(self::INVALID_OUTPUT === $process->getOutput(), 'Invalid input must use fixed output at debug verbosity.');
        self::assertTrue('' === $process->getErrorOutput(), 'Validation must not emit diagnostics.');
    }

    public function testUnicodeMinimumAndMaximumByteBoundary(): void
    {
        foreach ([str_repeat('é', 15), str_repeat('é', 2048)."\r\n"] as $index => $password) {
            $process = $this->provision($this->prefix.$index.'@example.test', $password);
            self::assertSame(0, $process->getExitCode(), 'Valid Unicode/bounded terminal input was rejected.');
            self::assertTrue(Uuid::isValid(trim($process->getOutput())));
            $this->safeOutput($process, $password);
        }
    }

    public function testNoninteractiveProvisioningRequiresExplicitPipeOption(): void
    {
        $password = Browser::secret();
        $process = $this->provision($this->prefix.'@example.test', $password, ['--no-interaction', '-vvv']);
        self::assertSame(2, $process->getExitCode());
        $this->safeOutput($process, $password);
        self::assertTrue(self::INVALID_OUTPUT === $process->getOutput(), 'Implicit stdin must use fixed validation output.');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEmails(): iterable
    {
        yield 'missing domain' => ['invalid'];
        yield 'non ASCII' => ['é@example.test'];
        yield 'embedded line break' => ["a\nb@example.test"];
        yield 'too long' => [str_repeat('a', 255).'@example.test'];
    }

    #[DataProvider('invalidEmails')]
    public function testInvalidEmailIsValidationFailureWithoutSecretDiagnostics(string $email): void
    {
        $password = Browser::secret();
        $process = $this->provision($email, $password, self::DEBUG_STDIN);
        self::assertSame(2, $process->getExitCode());
        $this->safeOutput($process, $password);
        self::assertFalse(str_contains($process->getOutput().$process->getErrorOutput(), 'Stack trace'));
        self::assertTrue(self::INVALID_OUTPUT === $process->getOutput(), 'Invalid email must use fixed output at debug verbosity.');
        self::assertTrue('' === $process->getErrorOutput(), 'Validation must not emit diagnostics.');
    }

    public function testConcurrentCaseVariantsProduceOneAccount(): void
    {
        $email = $this->prefix.'@example.test';
        $password = Browser::secret();
        $processes = [];
        foreach ([$email, strtoupper($email)] as $identifier) {
            $process = new Process(['php', 'bin/console', 'app:account:provision', $identifier, ...self::DEBUG_STDIN], dirname(__DIR__, 2), timeout: 30);
            $process->setInput($password);
            $process->start();
            $processes[] = $process;
        }
        $codes = [];
        foreach ($processes as $process) {
            $codes[] = $process->wait();
            $this->safeOutput($process, $password);
            if (1 === $process->getExitCode()) {
                self::assertTrue("Account provisioning failed.\n" === $process->getOutput(), 'Concurrent duplicate failure must use fixed output at debug verbosity.');
            }
        }
        sort($codes);
        self::assertSame([0, 1], $codes);
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM public.authenticating_account WHERE email = ?', [$email]));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCommandPasswords(): iterable
    {
        yield from self::invalidPasswords();
        // Only the CLI owns terminal framing; direct callers cannot discard a line ending.
        yield 'terminal LF' => [str_repeat('x', 15)."\n"];
        yield 'terminal CRLF' => [str_repeat('x', 15)."\r\n"];
    }

    #[DataProvider('invalidCommandPasswords')]
    public function testDirectCommandRejectsInvalidPasswordBeforeHashingOrPersistence(#[\SensitiveParameter] string $password): void
    {
        $hasher = $this->createStub(PasswordHasher::class);
        $hasher->method('hash')->willThrowException(new \LogicException('Invalid input reached the hasher.'));
        self::getContainer()->set(PasswordHasher::class, $hasher);
        $count = $this->connection->fetchOne('SELECT count(*) FROM public.authenticating_account');
        $rejected = false;
        try {
            $this->commands()->dispatch(new RegisterAccountCommand($this->prefix.'@example.test', $password));
        } catch (InvalidPassword|ValidationFailedException) {
            $rejected = true;
        } catch (\Throwable) {
            self::fail('Direct invalid password dispatch failed outside the validation boundary.');
        }
        self::assertTrue($rejected, 'Every command caller must satisfy the password policy.');
        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame($count, $this->connection->fetchOne('SELECT count(*) FROM public.authenticating_account'));
        $this->assertNoPlaintextStorage($password);
    }

    #[DataProvider('invalidEmails')]
    public function testDirectCommandRejectsInvalidEmailBeforeHashingOrPersistence(string $email): void
    {
        $hasher = $this->createStub(PasswordHasher::class);
        $hasher->method('hash')->willThrowException(new \LogicException('Invalid input reached the hasher.'));
        self::getContainer()->set(PasswordHasher::class, $hasher);
        $password = Browser::secret();
        $count = $this->connection->fetchOne('SELECT count(*) FROM public.authenticating_account');
        $rejected = false;
        try {
            $this->commands()->dispatch(new RegisterAccountCommand($email, $password));
        } catch (InvalidEmailAddress|ValidationFailedException) {
            $rejected = true;
        } catch (\Throwable) {
            self::fail('Direct invalid email dispatch failed outside the validation boundary.');
        }
        self::assertTrue($rejected, 'Every command caller must supply a valid email.');
        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame($count, $this->connection->fetchOne('SELECT count(*) FROM public.authenticating_account'));
        $this->assertNoPlaintextStorage($password);
    }

    public function testDirectCommandNormalizesRawEmailAndHashesUntrimmedPasswordInsideOwnedTransaction(): void
    {
        $password = '  '.Browser::secret().'  ';
        $email = $this->prefix.'@example.test';
        $native = $this->nativeHasher();
        $active = false;
        $preserved = false;
        $calls = 0;
        $hasher = $this->createStub(PasswordHasher::class);
        $hasher->method('hash')->willReturnCallback(function (#[\SensitiveParameter] string $actual) use ($native, $password, &$active, &$preserved, &$calls): string {
            ++$calls;
            $active = $this->connection->isTransactionActive();
            $preserved = hash_equals($password, $actual);

            return $native->hash($actual);
        });
        self::getContainer()->set(PasswordHasher::class, $hasher);

        $id = $this->commands()->dispatch(new RegisterAccountCommand(" \t\r\n".strtoupper($email)."\n\t ", $password));

        self::assertInstanceOf(Uuid::class, $id);
        self::assertSame(1, $calls, 'Registration must hash exactly once.');
        self::assertTrue($active, 'The native password hash must be computed inside the owned command transaction.');
        self::assertTrue($preserved, 'The handler must pass the original password to the Domain hasher port.');
        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame($email, $this->connection->fetchOne('SELECT email FROM public.authenticating_account WHERE id = ?', [$id->toRfc4122()]));
        $hash = $this->storedHash($id->toRfc4122());
        self::assertTrue(password_verify($password, $hash), 'The native hash must verify the raw password.');
        self::assertFalse(password_verify(trim($password), $hash), 'Password spaces must remain significant.');
        self::assertSame(13, password_get_info($hash)['options']['cost'] ?? null);
        $this->assertNoPlaintextStorage($password);
    }

    public function testHasherFailureThroughRealBusAndConsoleIsRedactedRollsBackAndRecovers(): void
    {
        $native = $this->nativeHasher();
        $directPassword = Browser::secret();
        $consolePassword = Browser::secret();
        $previousCanary = Browser::secret();
        $markerEmail = $this->prefix.'-rollback@example.test';
        $markerHash = password_hash(Browser::secret(), PASSWORD_BCRYPT, ['cost' => 4]);
        $hasher = new FailingPasswordHasher($native, $this->connection, $markerEmail, $markerHash, $previousCanary);
        self::getContainer()->set(PasswordHasher::class, $hasher);
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $handlers = $logger->getHandlers();
        $logs = new TestHandler();
        $logger->setHandlers([$logs]);
        try {
            $commands = $this->commands();
            $caught = null;
            try {
                $commands->dispatch(new RegisterAccountCommand($this->prefix.'-direct@example.test', $directPassword));
            } catch (\Throwable $failure) {
                $caught = $failure;
            }
            self::assertTrue(null !== $hasher->lastFailure && $caught === $hasher->lastFailure, 'The compiled bus must unwrap the actual handler failure.');
            self::assertFalse($this->connection->isTransactionActive());
            self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM public.authenticating_account WHERE email LIKE ?', [$this->prefix.'%']), 'The failed transaction must roll back its probe write.');

            self::assertNotNull(self::$kernel);
            $application = new Application(self::$kernel);
            $tester = new CommandTester($application->find('app:account:provision'));
            $tester->setInputs([$consolePassword]);
            self::assertSame(1, $tester->execute(['email' => $this->prefix.'-console@example.test', '--password-stdin' => true], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_DEBUG, 'capture_stderr_separately' => true]));
            $output = $tester->getDisplay().$tester->getErrorOutput();
            self::assertTrue("Account provisioning failed.\n" === $output, 'The real console catch must emit only the fixed failure at debug verbosity.');
            self::assertTrue($caught !== $hasher->lastFailure && str_contains($hasher->lastFailure->getMessage(), $consolePassword) && str_contains($hasher->lastFailure->getPrevious()?->getMessage() ?? '', $previousCanary), 'The console must exercise its own secret-bearing handler failure and previous exception.');
            self::assertFalse($this->connection->isTransactionActive());
            self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM public.authenticating_account WHERE email LIKE ?', [$this->prefix.'%']), 'The console failure must also roll back its probe write.');
            self::assertSame(1, $this->connection->fetchOne('SELECT 1'), 'The connection must recover after handler failure.');

            $hasher->fail = false;
            $goodPassword = Browser::secret();
            $id = $commands->dispatch(new RegisterAccountCommand($this->prefix.'-recovered@example.test', $goodPassword));
            self::assertInstanceOf(Uuid::class, $id);
            self::assertTrue(password_verify($goodPassword, $this->storedHash($id->toRfc4122())), 'The same compiled bus must accept a subsequent good command.');
            self::assertSame([true, true, true], $hasher->activeTransactions, 'Both failures and recovery must run under owned transactions.');
            self::assertFalse($this->connection->isTransactionActive());
            $records = $logs->getRecords();
            self::assertCount(1, $records);
            self::assertTrue('Account provisioning failed.' === $records[0]->message && ['operation' => 'provision_account'] === $records[0]->context, 'The console must log only fixed failure metadata.');
            $diagnostics = $output.json_encode($records, JSON_THROW_ON_ERROR);
            foreach ([$directPassword, $consolePassword, $previousCanary, $goodPassword, $markerHash] as $secret) {
                self::assertFalse(str_contains($diagnostics, $secret), 'Output/logs must not expose credentials or the exception chain.');
            }
            foreach ([$directPassword, $consolePassword, $previousCanary, $goodPassword] as $secret) {
                $this->assertNoPlaintextStorage($secret);
            }
        } finally {
            $logger->setHandlers($handlers);
        }
    }

    private function nativeHasher(): SymfonyPasswordHasher
    {
        $factory = self::getContainer()->get('security.password_hasher_factory');
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $factory);

        // Do not initialize the compiled adapter before replacing its Domain port.
        return new SymfonyPasswordHasher($factory);
    }

    private function assertNoPlaintextStorage(#[\SensitiveParameter] string $password): void
    {
        if ('' === $password) {
            return;
        }
        // Scan in PHP: plaintext never becomes an SQL parameter or diagnostic query.
        foreach ($this->connection->fetchFirstColumn('SELECT row_to_json(account)::text FROM public.authenticating_account account') as $row) {
            self::assertIsString($row);
            $values = json_decode($row, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($values);
            foreach ($values as $value) {
                if (is_string($value)) {
                    self::assertFalse(str_contains($value, $password), 'An account row contains plaintext password input.');
                }
            }
        }
        foreach ($this->connection->fetchAllAssociative('SELECT body, headers FROM public.platform_messaging_message') as $row) {
            foreach ($row as $value) {
                self::assertIsString($value);
                self::assertFalse(str_contains($value, $password), 'A transport row contains plaintext password input.');
                self::assertFalse(str_contains($value, 'RegisterAccountCommand'), 'Registration commands must never enter a transport.');
            }
        }
    }
}
