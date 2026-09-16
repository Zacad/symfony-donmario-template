<?php

declare(strict_types=1);

namespace App\Module\Authenticating\UI\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Authenticating\Application\GetAccountIdentity\GetAccountIdentityQuery;
use App\Module\Authenticating\Application\GetAccountIdentity\GetAccountIdentityResult;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Platform\Authorization\AuthorizationDenied;
use App\Platform\Messaging\QueryBus;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** @implements ProviderInterface<AccountIdentityResource> */
final readonly class AccountIdentityProvider implements ProviderInterface
{
    public function __construct(private TokenStorageInterface $tokens, private QueryBus $queries)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AccountIdentityResource
    {
        $principal = $this->tokens->getToken()?->getUser();
        if (!$principal instanceof AccountPrincipal) {
            throw new UnauthorizedHttpException('Bearer', 'Access denied.', headers: ['Cache-Control' => 'no-store']);
        }

        try {
            $identity = $this->queries->ask(new GetAccountIdentityQuery($principal->id()));
        } catch (AuthorizationDenied $failure) {
            if ($failure->authenticated) {
                throw new AccessDeniedHttpException('Access denied.', headers: ['Cache-Control' => 'no-store']);
            }

            throw new UnauthorizedHttpException('Bearer', 'Access denied.', headers: ['Cache-Control' => 'no-store']);
        }
        if (null === $identity) {
            throw new NotFoundHttpException('Account not found.');
        }
        if (!$identity instanceof GetAccountIdentityResult) {
            throw new \LogicException('Unexpected account identity result.');
        }

        return new AccountIdentityResource($identity->id->toRfc4122(), $identity->email);
    }
}
