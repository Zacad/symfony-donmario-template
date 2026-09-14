<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Symfony\Security;

use App\Module\Authenticating\Domain\PasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final readonly class SymfonyPasswordHasher implements PasswordHasher
{
    public function __construct(private PasswordHasherFactoryInterface $hashers)
    {
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return $this->hashers->getPasswordHasher('authenticating.password')->hash($password);
    }
}
