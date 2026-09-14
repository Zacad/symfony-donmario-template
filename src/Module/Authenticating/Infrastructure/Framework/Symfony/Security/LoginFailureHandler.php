<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Symfony\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

final readonly class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public const string ERROR_KEY = 'authenticating.login_error';

    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Normal native failures must not let an untrusted login POST log out
        // the previously authenticated user. Late failures clear explicitly.
        $request->getSession()->set(self::ERROR_KEY, 'bad_credentials');

        return new RedirectResponse('/login', 302, ['Cache-Control' => 'no-store']);
    }

    public function clearAuthentication(Request $request): void
    {
        // PasswordMigratingListener runs after the token has already been installed.
        $this->tokens->setToken(null);
        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->remove('_security_main');
            $session->invalidate();
        }
    }
}
