<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\RegisterAccount;

use App\Module\Authenticating\Domain\Account;
use App\Module\Authenticating\Domain\AccountRepository;
use App\Module\Authenticating\Domain\EmailAddress;
use App\Module\Authenticating\Domain\PasswordHasher;
use App\Module\Authenticating\Domain\PasswordPolicy;
use App\Platform\Authorization\AuthorizeWith;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
#[AuthorizeWith(RegisterAccountPolicy::class)]
final readonly class RegisterAccountHandler
{
    public function __construct(private AccountRepository $accounts, private PasswordHasher $passwordHasher)
    {
    }

    public function __invoke(RegisterAccountCommand $command): Uuid
    {
        $email = EmailAddress::normalize($command->email);
        PasswordPolicy::validate($command->password);
        $account = new Account($email, $this->passwordHasher->hash($command->password));
        $this->accounts->add($account);

        return $account->id();
    }
}
