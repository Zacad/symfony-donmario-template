<?php

declare(strict_types=1);

namespace App\Module\Authenticating\UI\Http\Security;

use App\Module\Authenticating\Domain\AccountRepository;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

/** @implements UserProviderInterface<AccountPrincipal> */
final readonly class BearerAccountUserProvider implements UserProviderInterface
{
    public function __construct(private AccountRepository $accounts)
    {
    }

    public function loadUserByIdentifier(string $identifier): AccountPrincipal
    {
        if (!Uuid::isValid($identifier, Uuid::FORMAT_RFC_4122)) {
            throw new UserNotFoundException('Account not found.');
        }
        try {
            $credentials = $this->accounts->findCredentialsById(Uuid::fromString($identifier));
        } catch (\Throwable) {
            throw new ServiceUnavailableHttpException(null, 'Authentication unavailable.');
        }
        if (null === $credentials) {
            throw new UserNotFoundException('Account not found.');
        }

        return new AccountPrincipal($credentials->id, $credentials->email, $credentials->passwordHash);
    }

    public function refreshUser(UserInterface $user): AccountPrincipal
    {
        if (!$user instanceof AccountPrincipal) {
            throw new UnsupportedUserException('Unsupported account principal.');
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return AccountPrincipal::class === $class;
    }
}
