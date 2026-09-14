<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Domain;

use Symfony\Component\Uid\Uuid;

interface AccountRepository
{
    public function add(Account $account): void;

    public function findCredentialsByEmail(string $email): ?AccountCredentials;

    public function findCredentialsById(Uuid $id): ?AccountCredentials;

    public function findIdentityById(Uuid $id): ?AccountIdentity;

    public function replacePasswordHash(Uuid $id, string $expectedPasswordHash, string $newPasswordHash): bool;
}
