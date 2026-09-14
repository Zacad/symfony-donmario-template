<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Application\GetAccountIdentity;

use App\Module\Authenticating\Domain\AccountRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetAccountIdentityHandler
{
    public function __construct(private AccountRepository $accounts)
    {
    }

    public function __invoke(GetAccountIdentityQuery $query): ?GetAccountIdentityResult
    {
        $identity = $this->accounts->findIdentityById($query->id);

        return null === $identity ? null : new GetAccountIdentityResult($identity->id, $identity->email);
    }
}
