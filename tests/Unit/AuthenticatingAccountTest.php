<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authenticating\Application\RegisterAccount\RegisterAccountCommand;
use App\Module\Authenticating\Application\RegisterAccount\RegisterAccountHandler;
use App\Module\Authenticating\Application\UpgradePasswordHash\UpgradePasswordHashCommand;
use App\Module\Authenticating\Application\UpgradePasswordHash\UpgradePasswordHashHandler;
use App\Module\Authenticating\Domain\Account;
use App\Module\Authenticating\Domain\AccountRepository;
use App\Module\Authenticating\Domain\EmailAddress;
use App\Module\Authenticating\Domain\InvalidEmailAddress;
use App\Module\Authenticating\Domain\InvalidPassword;
use App\Module\Authenticating\Domain\InvalidPasswordHash;
use App\Module\Authenticating\Domain\PasswordHasher;
use App\Module\Authenticating\Domain\PasswordPolicy;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\SymfonyPasswordHasher;
use App\Module\Authenticating\UI\Console\ProvisionAccountConsoleCommand;
use App\Platform\Authorization\ExecutionContext;
use App\Platform\Authorization\OperatorExecution;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\InvocationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Validator\Validation;

final class AuthenticatingAccountTest extends TestCase
{
    private function operator(): OperatorExecution
    {
        return new OperatorExecution(new ExecutionContext(new InvocationContext(), new RequestStack(), new TokenStorage(), new AuthenticationTrustResolver()));
    }

    private const string HASH = '$2y$04$abcdefghijklmnopqrstuuYy7AtPAWqBDnJt5Mrk.CkOC7eCng1.O';

    public function testAccountNormalizesOnlyCaseAndOuterAsciiWhitespace(): void
    {
        $account = new Account(" \tFirst.Last+Tag@Example.COM\r\n", self::HASH);

        self::assertSame('first.last+tag@example.com', $account->email());
        self::assertTrue(hash_equals(self::HASH, $account->passwordHash()), 'Only the supplied hash is stored.');
        self::assertInstanceOf(UuidV7::class, $account->id());
    }

    #[DataProvider('invalidEmails')]
    public function testInvalidEmailIsRejectedWithoutEchoingInput(string $email): void
    {
        $this->expectException(InvalidEmailAddress::class);
        $this->expectExceptionMessage('Email must be a valid ASCII address of at most 254 bytes.');
        EmailAddress::normalize($email);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEmails(): iterable
    {
        yield 'missing domain' => ['private-email'];
        yield 'unicode' => ['ü@example.com'];
        yield 'unicode whitespace' => ["\u{00a0}user@example.com"];
        yield 'NUL' => ["user@example.com\0"];
        yield 'too long' => [str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 63).'.com'];
    }

    #[DataProvider('invalidPasswords')]
    public function testPasswordPolicyRejectsInvalidAndOversizedInput(string $password): void
    {
        $this->expectException(InvalidPassword::class);
        PasswordPolicy::validate($password);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPasswords(): iterable
    {
        yield 'empty' => [''];
        yield 'short ascii' => [str_repeat('a', 14)];
        yield 'codepoints not bytes' => [str_repeat('界', 14)];
        yield 'oversized bytes' => [str_repeat('界', 1366)];
        yield 'invalid UTF8' => [str_repeat('a', 15)."\xff"];
        yield 'NUL' => [str_repeat('a', 15)."\0"];
        yield 'CR' => [str_repeat('a', 15)."\r"];
        yield 'LF' => [str_repeat('a', 15)."\n"];
    }

    public function testNativeHashingPreservesWhitespaceAndSupportsPolicyBoundaries(): void
    {
        $factory = new PasswordHasherFactory(['authenticating.password' => ['algorithm' => 'auto', 'cost' => 4, 'time_cost' => 3, 'memory_cost' => 10]]);
        $hasher = new SymfonyPasswordHasher($factory);
        $native = $factory->getPasswordHasher('authenticating.password');
        foreach ([' '.str_repeat('界', 15).' ', str_repeat(' ', 15), str_repeat('x', 4096)] as $password) {
            $hash = $hasher->hash($password);
            $account = new Account('person@example.com', $hash);
            self::assertFalse(hash_equals($password, $account->passwordHash()), 'Stored credentials must not be plaintext.');
            self::assertTrue($native->verify($account->passwordHash(), $password));
            self::assertFalse($native->verify($account->passwordHash(), 'wrong password'));
        }
        $password = ' '.str_repeat('界', 15).' ';
        self::assertFalse($native->verify($hasher->hash($password), trim($password)));
    }

    public function testRegistrationNormalizesEmailAndStoresOnlyTheDerivedNativeHash(): void
    {
        $factory = new PasswordHasherFactory(['authenticating.password' => ['algorithm' => 'auto', 'cost' => 4, 'time_cost' => 3, 'memory_cost' => 10]]);
        $native = $factory->getPasswordHasher('authenticating.password');
        foreach ([' '.str_repeat('界', 15).' ', str_repeat(' ', 15), str_repeat('x', 4096)] as $password) {
            $stored = null;
            $accounts = $this->createMock(AccountRepository::class);
            $accounts->expects(self::once())->method('add')->willReturnCallback(static function (Account $account) use (&$stored): void {
                $stored = $account;
            });
            $handler = new RegisterAccountHandler($accounts, new SymfonyPasswordHasher($factory));
            $id = $handler(new RegisterAccountCommand(str_repeat(' ', 255)."\tPERSON@Example.COM\r\n", $password));

            self::assertInstanceOf(Account::class, $stored);
            self::assertSame($stored->id(), $id);
            self::assertSame('person@example.com', $stored->email());
            self::assertFalse(hash_equals($password, $stored->passwordHash()), 'Registration must never store plaintext.');
            self::assertTrue($native->verify($stored->passwordHash(), $password), 'The stored native hash verifies the original password.');
            self::assertFalse($native->verify($stored->passwordHash(), 'wrong password'));
        }
    }

    #[DataProvider('invalidPasswords')]
    public function testRegistrationRejectsInvalidPasswordsBeforeHashingOrPersistence(string $password): void
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects(self::never())->method('add');
        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::never())->method('hash');

        $this->expectException(InvalidPassword::class);
        (new RegisterAccountHandler($accounts, $hasher))(new RegisterAccountCommand('person@example.com', $password));
    }

    #[DataProvider('invalidEmails')]
    public function testRegistrationRejectsInvalidEmailBeforePasswordPolicyHashingOrPersistence(string $email): void
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects(self::never())->method('add');
        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::never())->method('hash');

        $this->expectException(InvalidEmailAddress::class);
        (new RegisterAccountHandler($accounts, $hasher))(new RegisterAccountCommand($email, 'short'));
    }

    public function testRegistrationValidationAllowsDomainNormalizationAndLeavesCompletePolicyToHandler(): void
    {
        $validator = Validation::createValidatorBuilder()->addYamlMapping(__DIR__.'/../../src/Module/Authenticating/Resources/config/validation.yaml')->getValidator();
        foreach ([str_repeat(' ', 15), str_repeat('x', 4096), 'short'] as $password) {
            self::assertCount(0, $validator->validate(new RegisterAccountCommand(str_repeat(' ', 255)."\tPERSON@Example.COM\r\n", $password)));
        }
        foreach (['' => 'Password is required.', str_repeat('x', 4097) => 'Password must contain at most 4096 bytes.'] as $password => $message) {
            $violations = $validator->validate(new RegisterAccountCommand('person@example.com', $password));
            self::assertCount(1, $violations);
            self::assertSame('password', $violations->get(0)->getPropertyPath());
            self::assertSame($message, $violations->get(0)->getMessage());
        }
        self::assertCount(1, $validator->validate(new RegisterAccountCommand('', 'a private password')));
        self::assertCount(0, $validator->validate(new UpgradePasswordHashCommand(Uuid::v7(), self::HASH, self::HASH)));
        self::assertCount(2, $validator->validate(new UpgradePasswordHashCommand(Uuid::v7(), 'invalid', 'invalid')));
    }

    #[DataProvider('invalidHashes')]
    public function testAccountRejectsPlaintextAndMalformedHashes(string $hash): void
    {
        $this->expectException(InvalidPasswordHash::class);
        $this->expectExceptionMessage('Password hash must use a supported bounded format.');
        new Account('person@example.com', $hash);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidHashes(): iterable
    {
        yield 'plaintext' => ['a private password'];
        yield 'oversized' => [str_repeat('x', 256)];
        yield 'invalid bcrypt cost' => [str_replace('$04$', '$99$', self::HASH)];
        yield 'truncated bcrypt' => [substr(self::HASH, 0, -1)];
        yield 'line suffix' => [self::HASH."\n"];
        yield 'unbounded argon parameters' => ['$argon2id$v=19$m=999999999999999999,t=1,p=1$abcdefghijklmnop$'.str_repeat('a', 43)];
    }

    public function testStalePasswordUpgradeReturnsFalseInsteadOfReplacingUnconditionally(): void
    {
        $id = Uuid::v7();
        $newHash = str_replace('$04$', '$05$', self::HASH);
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects(self::once())->method('replacePasswordHash')->willReturnCallback(static function (Uuid $accountId, string $expectedPasswordHash, string $newPasswordHash) use ($id, $newHash): bool {
            self::assertSame($id, $accountId);
            self::assertTrue(hash_equals(self::HASH, $expectedPasswordHash), 'The expected hash is preserved.');
            self::assertTrue(hash_equals($newHash, $newPasswordHash), 'The replacement hash is preserved.');

            return false;
        });

        self::assertFalse((new UpgradePasswordHashHandler($accounts))(new UpgradePasswordHashCommand($id, self::HASH, $newHash)));
    }

    #[DataProvider('stdinPasswords')]
    public function testProvisioningFramesRawInputAndHandlerEnforcesPolicy(string $wire, ?string $password, bool $explicitStdin = true, bool $dispatched = true): void
    {
        $id = null;
        $email = ' PERSON@Example.COM ';
        $bus = $this->registrationBus($password, $dispatched, $email, $id);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $command = new ProvisionAccountConsoleCommand($bus, $logger, $this->operator());
        $input = new ArrayInput(['email' => $email, '--password-stdin' => $explicitStdin]);
        $input->setInteractive(false);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $wire);
        rewind($stream);
        $input->setStream($stream);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);
        try {
            self::assertSame(null === $password ? 2 : 0, $command->run($input, $output));
        } finally {
            fclose($stream);
        }
        $display = $output->fetch();
        self::assertFalse(str_contains($display, self::HASH), 'Output must not contain credentials.');
        if (null !== $password) {
            self::assertInstanceOf(Uuid::class, $id);
            self::assertSame($id->toRfc4122()."\n", $display);
        }
    }

    /** @return iterable<string, array{0: string, 1: ?string, 2?: bool, 3?: bool}> */
    public static function stdinPasswords(): iterable
    {
        $password = '  private password  ';
        yield 'no newline' => [$password, $password];
        yield 'one LF' => [$password."\n", $password];
        yield 'one CRLF' => [$password."\r\n", $password];
        yield 'maximum with CRLF' => [str_repeat('x', 4096)."\r\n", str_repeat('x', 4096)];
        yield 'too long' => [str_repeat('x', 4097)."\n", null];
        yield 'bounded overflow' => [str_repeat('x', 8192), null, true, false];
        yield 'additional line' => [$password."\nother secret\n", null];
        yield 'additional blank line' => [$password."\n\n", null];
        yield 'NUL' => [$password."\0", null];
        yield 'short' => ['short', null];
        yield 'no implicit stdin' => [$password, null, false, false];
    }

    public function testProvisioningRedactsOperationAndLoggerFailuresAtDebugVerbosity(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException(new \RuntimeException('private database details '.self::HASH));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Account provisioning failed.', ['operation' => 'provision_account'])->willThrowException(new \RuntimeException('private logger details'));
        $command = new ProvisionAccountConsoleCommand(new CommandBus($bus), $logger, $this->operator());
        $input = new ArrayInput(['email' => 'person@example.com', '--password-stdin' => true]);
        $input->setInteractive(false);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'a private password');
        rewind($stream);
        $input->setStream($stream);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);
        try {
            self::assertSame(1, $command->run($input, $output));
        } finally {
            fclose($stream);
        }
        self::assertSame("Account provisioning failed.\n", $output->fetch());
    }

    #[DataProvider('invalidEmails')]
    public function testProvisioningReportsHandlerEmailRejectionAsInvalidInput(string $email): void
    {
        $id = null;
        $bus = $this->registrationBus(null, true, $email, $id);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $tester = new CommandTester(new ProvisionAccountConsoleCommand($bus, $logger, $this->operator()));
        $tester->setInputs(['a private password']);

        self::assertSame(2, $tester->execute(['email' => $email, '--password-stdin' => true], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_DEBUG]));
        self::assertStringStartsWith('Invalid account input.', $tester->getDisplay());
        self::assertFalse(str_contains($tester->getDisplay(), 'a private password'), 'Invalid input diagnostics must not expose the password.');
    }

    public function testProvisioningRedactsNativeValidationFailureAtDebugVerbosity(): void
    {
        $validator = Validation::createValidatorBuilder()->addYamlMapping(__DIR__.'/../../src/Module/Authenticating/Resources/config/validation.yaml')->getValidator();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $envelope) use ($validator): never {
            self::assertInstanceOf(Envelope::class, $envelope);
            $message = $envelope->getMessage();
            $violations = $validator->validate($message);
            self::assertCount(1, $violations);

            throw new ValidationFailedException($message, $violations);
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $tester = new CommandTester(new ProvisionAccountConsoleCommand(new CommandBus($bus), $logger, $this->operator()));
        $password = str_repeat('x', 4097);
        $tester->setInputs([$password]);

        self::assertSame(2, $tester->execute(['email' => 'person@example.com', '--password-stdin' => true], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_DEBUG]));
        self::assertStringStartsWith('Invalid account input.', $tester->getDisplay());
        self::assertFalse(str_contains($tester->getDisplay(), $password), 'Validation diagnostics must not expose the rejected password.');
    }

    #[DataProvider('hiddenPasswords')]
    public function testHiddenPromptLineFramingPreservesPasswordAndConfirmation(string $answer, string $confirmation, ?string $password, bool $dispatched = true): void
    {
        $id = null;
        $bus = $this->registrationBus($password, $dispatched, 'person@example.com', $id);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $questions = $this->createMock(QuestionHelper::class);
        $answers = [$answer, $confirmation];
        $questions->expects(self::exactly(2))->method('ask')->willReturnCallback(static function (InputInterface $input, OutputInterface $output, Question $question) use (&$answers): string {
            self::assertTrue($question->isHidden());
            self::assertFalse($question->isHiddenFallback());
            self::assertFalse($question->isTrimmable());

            return array_shift($answers) ?? throw new \LogicException('Unexpected question.');
        });
        $command = new ProvisionAccountConsoleCommand($bus, $logger, $this->operator());
        $command->setHelperSet(new HelperSet(['question' => $questions]));
        $input = new ArrayInput(['email' => 'person@example.com']);
        $input->setInteractive(true);
        // A real terminal stream retains the production TTY gate; only prompt answers are mocked.
        $process = proc_open(['/bin/true'], [0 => ['pty']], $pipes);
        self::assertIsResource($process);
        try {
            self::assertTrue(stream_isatty($pipes[0]));
            $input->setStream($pipes[0]);
            $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);
            self::assertSame(null === $password ? 2 : 0, $command->run($input, $output));
            $display = $output->fetch();
            self::assertFalse(str_contains($display, self::HASH), 'Output must not contain credentials.');
            if (null !== $password) {
                self::assertInstanceOf(Uuid::class, $id);
                self::assertSame($id->toRfc4122()."\n", $display);
            }
        } finally {
            fclose($pipes[0]);
            proc_close($process);
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: ?string, 3?: bool}> */
    public static function hiddenPasswords(): iterable
    {
        $password = " \tprivate password\t  ";
        yield 'LF' => [$password."\n", $password."\n", $password];
        yield 'CRLF' => [$password."\r\n", $password."\r\n", $password];
        yield 'different framing' => [$password."\n", $password."\r\n", $password];
        yield 'no framing' => [$password, $password, $password];
        yield 'spaces are significant' => [$password."\n", trim($password)."\n", null, false];
        yield 'extra newline' => [$password."\n\n", $password."\n\n", null];
        yield 'embedded newline' => [$password."\nextra\n", $password."\nextra\n", null];
        yield 'embedded NUL' => [$password."\0\n", $password."\0\n", null];
        yield 'bare CR' => [$password."\r", $password."\r", null];
    }

    private function registrationBus(?string $password, bool $dispatched, string $email, ?Uuid &$id): CommandBus
    {
        $hasher = $this->createMock(PasswordHasher::class);
        $accounts = $this->createMock(AccountRepository::class);
        if (null === $password) {
            $hasher->expects(self::never())->method('hash');
            $accounts->expects(self::never())->method('add');
        } else {
            $hasher->expects(self::once())->method('hash')->willReturnCallback(static function (string $actual) use ($password): string {
                self::assertTrue(hash_equals($password, $actual), 'The handler hashes the original framed password.');

                return self::HASH;
            });
            $accounts->expects(self::once())->method('add')->willReturnCallback(static function (Account $account) use (&$id): void {
                self::assertSame('person@example.com', $account->email());
                self::assertTrue(hash_equals(self::HASH, $account->passwordHash()), 'Only the derived hash reaches persistence.');
                $id = $account->id();
            });
        }
        $handler = new RegisterAccountHandler($accounts, $hasher);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($dispatched ? self::once() : self::never())->method('dispatch')->willReturnCallback(static function (object $envelope) use ($handler, $email): Envelope {
            self::assertInstanceOf(Envelope::class, $envelope);
            $message = $envelope->getMessage();
            self::assertInstanceOf(RegisterAccountCommand::class, $message);
            self::assertSame($email, $message->email, 'The adapter dispatches the raw email.');
            try {
                return $envelope->with(new HandledStamp($handler($message), 'register_account'));
            } catch (InvalidEmailAddress|InvalidPassword $failure) {
                throw new HandlerFailedException($envelope, [$failure]);
            }
        });

        return new CommandBus($bus);
    }
}
