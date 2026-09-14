<?php

declare(strict_types=1);

namespace App\Module\Authenticating\UI\Api;

use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
final readonly class LoginController
{
    public function __construct(private JWTTokenManagerInterface $tokens)
    {
    }

    // json_login has no success handler: all native migration listeners finish first.
    #[Route('/api/login', name: 'authenticating_api_login', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(#[CurrentUser] AccountPrincipal $user): JsonResponse
    {
        return new JsonResponse([
            'access_token' => $this->tokens->create($user),
            'token_type' => 'Bearer',
            'expires_in' => 900,
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
