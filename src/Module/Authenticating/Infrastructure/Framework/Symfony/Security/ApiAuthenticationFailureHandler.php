<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Symfony\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class ApiAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface, AuthenticationEntryPointInterface, AccessDeniedHandlerInterface
{
    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->respond($exception instanceof TooManyLoginAttemptsAuthenticationException ? 429 : 401);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->respond(401);
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return $this->respond(403);
    }

    public function respond(int $status): JsonResponse
    {
        // API failures clear request authentication only, never a browser session.
        $this->tokens->setToken(null);

        return new JsonResponse(['error' => match ($status) {
            401 => 'Invalid credentials.',
            403 => 'Access denied.',
            404 => 'Not found.',
            405 => 'Method not allowed.',
            413 => 'Request too large.',
            415 => 'Unsupported content type.',
            429 => 'Too many login attempts.',
            503 => 'Authentication unavailable.',
            default => 'Invalid request.',
        }], $status, ['Cache-Control' => 'no-store'] + (401 === $status ? ['WWW-Authenticate' => 'Bearer'] : []));
    }
}
