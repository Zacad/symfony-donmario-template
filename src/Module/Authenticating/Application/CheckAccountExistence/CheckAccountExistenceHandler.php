<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\CheckAccountExistence;

use App\Module\Authenticating\Domain\AccountRepository;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AuthenticatingVoter;
use App\Platform\Authorization\Authorize;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
#[Authorize(AuthenticatingVoter::class)]
final readonly class CheckAccountExistenceHandler
{
    public function __construct(private AccountRepository $accounts)
    {
    }

    public function __invoke(CheckAccountExistenceQuery $query): CheckAccountExistenceResult
    {
        $unique = [];
        foreach ($query->accountIds as $id) {
            $unique[$id->toRfc4122()] = $id;
        }

        $existing = [];
        foreach ($this->accounts->existingIds(array_values($unique)) as $id) {
            $existing[$id->toRfc4122()] = true;
        }

        $accounts = [];
        foreach ($query->accountIds as $id) {
            $accounts[] = new AccountExistenceResult($id, isset($existing[$id->toRfc4122()]));
        }

        return new CheckAccountExistenceResult($accounts);
    }
}
