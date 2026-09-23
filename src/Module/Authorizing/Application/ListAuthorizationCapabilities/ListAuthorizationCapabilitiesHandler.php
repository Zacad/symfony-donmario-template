<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Application\ListAuthorizationCapabilities;

use App\Module\Authorizing\Domain\Capability\AuthorizationCatalogService;
use App\Module\Authorizing\Domain\Capability\AuthorizingPermissionEnum;
use App\Module\Authorizing\Domain\InvalidAuthorizationInputException;
use App\Module\Authorizing\Infrastructure\Framework\Symfony\Security\AuthorizingVoter;
use App\Platform\Authorization\Authorize;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
#[Authorize(voter: AuthorizingVoter::class, permission: AuthorizingPermissionEnum::CatalogueManage, label: 'Manage authorization catalogue')]
final readonly class ListAuthorizationCapabilitiesHandler
{
    public function __construct(private AuthorizationCatalogService $catalog)
    {
    }

    public function __invoke(ListAuthorizationCapabilitiesQuery $query): ListAuthorizationCapabilitiesResult
    {
        if ($query->limit < 1 || $query->limit > 100) {
            throw new InvalidAuthorizationInputException();
        }
        $capabilities = $this->catalog->after($query->afterKey, $query->limit);
        $hasMore = count($capabilities) > $query->limit;
        $capabilities = array_slice($capabilities, 0, $query->limit);
        $results = array_map(static fn (array $capability): AuthorizationCapabilityResult => new AuthorizationCapabilityResult(
            $capability['module'],
            $capability['key'],
            $capability['label'],
            $capability['access'],
            $capability['operations'],
        ), $capabilities);

        return new ListAuthorizationCapabilitiesResult($results, $hasMore && [] !== $capabilities ? $capabilities[array_key_last($capabilities)]['key'] : null);
    }
}
