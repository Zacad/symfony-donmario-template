<?php

declare(strict_types=1);

namespace App\Module\Authenticating\UI\Http\Security;

use App\Module\Authenticating\Application\UpgradePasswordHash\UpgradePasswordHashCommand;
use App\Module\Authenticating\Domain\AccountCredentials;
use App\Module\Authenticating\Domain\AccountRepository;
use App\Module\Authenticating\Domain\EmailAddress;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Platform\Messaging\CommandBus;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<AccountPrincipal> */
final readonly class AccountUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private AccountRepository $accounts, private CommandBus $commands)
    {
    }

    public function loadUserByIdentifier(string $identifier): AccountPrincipal
    {
        try {
            $email = EmailAddress::normalize($identifier);
        } catch (\InvalidArgumentException) {
            throw new UserNotFoundException('Account not found.');
        }
        try {
            $credentials = $this->accounts->findCredentialsByEmail($email);
        } catch (\Throwable) {
            // Never attach database exceptions (or their arguments) to Security logs.
            throw new ServiceUnavailableHttpException(null, 'Authentication unavailable.');
        }

        return $this->principal($credentials);
    }

    public function refreshUser(UserInterface $user): AccountPrincipal
    {
        if (!$user instanceof AccountPrincipal) {
            throw new UnsupportedUserException('Unsupported account principal.');
        }
        try {
            $credentials = $this->accounts->findCredentialsById($user->id());
        } catch (\Throwable) {
            throw new ServiceUnavailableHttpException(null, 'Authentication unavailable.');
        }

        return $this->principal($credentials);
    }

    public function supportsClass(string $class): bool
    {
        return AccountPrincipal::class === $class;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $newHashedPassword): void
    {
        if (!$user instanceof AccountPrincipal) {
            throw new UnsupportedUserException('Unsupported account principal.');
        }
        try {
            $upgraded = $this->commands->dispatch(new UpgradePasswordHashCommand($user->id(), $user->getPassword(), $newHashedPassword));
        } catch (\Throwable) {
            throw new ServiceUnavailableHttpException(null, 'Authentication unavailable.');
        }
        if (true !== $upgraded) {
            throw new BadCredentialsException('Bad credentials.');
        }
        $user->replacePasswordHash($newHashedPassword);
    }

    private function principal(?AccountCredentials $credentials): AccountPrincipal
    {
        if (null === $credentials) {
            throw new UserNotFoundException('Account not found.');
        }

        return new AccountPrincipal($credentials->id, $credentials->email, $credentials->passwordHash);
    }
}
