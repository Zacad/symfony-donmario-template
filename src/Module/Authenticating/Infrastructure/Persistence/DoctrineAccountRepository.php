<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Persistence;

use App\Module\Authenticating\Domain\Account;
use App\Module\Authenticating\Domain\AccountCredentials;
use App\Module\Authenticating\Domain\AccountIdentity;
use App\Module\Authenticating\Domain\AccountRepository;
use App\Module\Authenticating\Domain\EmailAddress;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAccountRepository implements AccountRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(Account $account): void
    {
        $this->entityManager->persist($account);
    }

    public function findCredentialsByEmail(string $email): ?AccountCredentials
    {
        return $this->credentials($this->entityManager->getConnection()->fetchAssociative(
            'SELECT id, email, password_hash FROM public.authenticating_account WHERE email = ?',
            [EmailAddress::normalize($email)],
        ));
    }

    public function findCredentialsById(Uuid $id): ?AccountCredentials
    {
        return $this->credentials($this->entityManager->getConnection()->fetchAssociative(
            'SELECT id, email, password_hash FROM public.authenticating_account WHERE id = ?',
            [$id->toRfc4122()],
        ));
    }

    public function findIdentityById(Uuid $id): ?AccountIdentity
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT id, email FROM public.authenticating_account WHERE id = ?',
            [$id->toRfc4122()],
        );
        if (false === $row) {
            return null;
        }
        if (!is_string($row['id']) || !is_string($row['email'])) {
            throw new \UnexpectedValueException('Invalid stored account identity.');
        }

        return new AccountIdentity(Uuid::fromString($row['id']), $row['email']);
    }

    public function replacePasswordHash(Uuid $id, string $expectedPasswordHash, string $newPasswordHash): bool
    {
        return 1 === $this->entityManager->getConnection()->executeStatement(
            'UPDATE public.authenticating_account SET password_hash = ? WHERE id = ? AND password_hash = ?',
            [$newPasswordHash, $id->toRfc4122(), $expectedPasswordHash],
        );
    }

    /** @param array<string, mixed>|false $row */
    private function credentials(array|false $row): ?AccountCredentials
    {
        if (false === $row) {
            return null;
        }
        if (!is_string($row['id']) || !is_string($row['email']) || !is_string($row['password_hash'])) {
            throw new \UnexpectedValueException('Invalid stored account credentials.');
        }

        return new AccountCredentials(Uuid::fromString($row['id']), $row['email'], $row['password_hash']);
    }
}
