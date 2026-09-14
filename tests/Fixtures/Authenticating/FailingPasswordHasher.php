<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Authenticating;

use App\Module\Authenticating\Domain\PasswordHasher;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Runtime-only test replacement; never registered in production service discovery. */
final class FailingPasswordHasher implements PasswordHasher
{
    public bool $fail = true;
    /** @var list<bool> */
    public array $activeTransactions = [];
    public ?\RuntimeException $lastFailure = null;

    public function __construct(
        private readonly PasswordHasher $native,
        private readonly Connection $connection,
        private readonly string $markerEmail,
        #[\SensitiveParameter] private readonly string $markerHash,
        #[\SensitiveParameter] private readonly string $previousCanary,
    ) {
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        $this->activeTransactions[] = $this->connection->isTransactionActive();
        if ($this->fail) {
            // A test-only write proves rollback, rather than absence of a not-yet-added account.
            $this->connection->insert('public.authenticating_account', ['id' => Uuid::v7()->toRfc4122(), 'email' => $this->markerEmail, 'password_hash' => $this->markerHash]);
            $this->lastFailure = new \RuntimeException('Hasher failed: '.$password, previous: new \LogicException('Previous failure: '.$password.' '.$this->previousCanary));
            throw $this->lastFailure;
        }

        return $this->native->hash($password);
    }
}
