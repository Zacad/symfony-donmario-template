<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Symfony\Security;

use Symfony\Component\PasswordHasher\Hasher\PasswordHasherAwareInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

final class AccountPrincipal implements UserInterface, PasswordAuthenticatedUserInterface, PasswordHasherAwareInterface
{
    public function __construct(private readonly Uuid $id, private readonly string $email, #[\SensitiveParameter] private string $passwordHash)
    {
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        return $this->id->toRfc4122();
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getPasswordHasherName(): string
    {
        return 'authenticating.password';
    }

    /** Called by the native password upgrader only after its command commits. */
    public function replacePasswordHash(#[\SensitiveParameter] string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    /** @return array{id: string, email: string, passwordHash: string} */
    public function __serialize(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'email' => $this->email,
            // ContextListener natively compares this fingerprint to the refreshed hash.
            'passwordHash' => 8 === \strlen($this->passwordHash) ? $this->passwordHash : hash('crc32c', $this->passwordHash),
        ];
    }

    /** @param array{id: string, email: string, passwordHash: string} $data */
    public function __unserialize(array $data): void
    {
        $this->id = Uuid::fromString($data['id']);
        $this->email = $data['email'];
        $this->passwordHash = $data['passwordHash'];
    }

    /** @return array{id: string, email: string} */
    public function __debugInfo(): array
    {
        return ['id' => $this->id->toRfc4122(), 'email' => $this->email];
    }
}
