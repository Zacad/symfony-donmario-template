<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\UpgradePasswordHash;

use App\Module\Authenticating\Domain\AccountRepository;
use App\Module\Authenticating\Domain\PasswordHash;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AuthenticatingVoter;
use App\Platform\Authorization\Authorize;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
#[Authorize(AuthenticatingVoter::class)]
final readonly class UpgradePasswordHashHandler
{
    public function __construct(private AccountRepository $accounts)
    {
    }

    public function __invoke(UpgradePasswordHashCommand $command): bool
    {
        PasswordHash::validate($command->expectedPasswordHash);
        PasswordHash::validate($command->newPasswordHash);

        return $this->accounts->replacePasswordHash($command->accountId, $command->expectedPasswordHash, $command->newPasswordHash);
    }
}
