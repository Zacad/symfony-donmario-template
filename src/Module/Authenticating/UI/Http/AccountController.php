<?php

declare(strict_types=1);

namespace App\Module\Authenticating\UI\Http;

use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\LoginFailureHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Twig\Environment;

#[AsController]
final readonly class AccountController
{
    public function __construct(private Environment $twig)
    {
    }

    #[Route('/login', name: 'authenticating_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        return new Response($this->twig->render('@Authenticating/login.html.twig', [
            'login_failed' => 'bad_credentials' === $request->getSession()->remove(LoginFailureHandler::ERROR_KEY),
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/account', name: 'authenticating_account', methods: ['GET'])]
    public function account(#[CurrentUser] AccountPrincipal $user): Response
    {
        return new Response($this->twig->render('@Authenticating/account.html.twig', [
            'account_id' => $user->getUserIdentifier(),
            'account_email' => $user->email(),
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/logout', name: 'authenticating_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('The native firewall must handle logout.');
    }
}
