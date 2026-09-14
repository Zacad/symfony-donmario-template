<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Symfony\EventListener;

use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\LoginFailureHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\LogoutException;

// Before both the firewall exception listener and HttpKernel's exception logger.
#[AsEventListener(event: ExceptionEvent::class, priority: 2048)]
final readonly class AuthenticationExceptionListener
{
    public function __construct(private LoginFailureHandler $failures, private LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!preg_match('{^/(?:login|logout|account)(?:/|$)}D', rawurldecode($request->getPathInfo()))) {
            return;
        }
        $failure = $event->getThrowable();
        if ($failure instanceof LogoutException) {
            // Invalid logout CSRF must not log the user out.
            $event->setResponse(new Response('Invalid request.', 403, ['Cache-Control' => 'no-store']));

            return;
        }
        if ($failure instanceof AccessDeniedException) {
            // Let native Security start form_login for an anonymous /account.
            return;
        }
        if ($failure instanceof AuthenticationException && '/login' === $request->getPathInfo()) {
            // Native manager failures already use the presentation-only handler.
            // Exceptions escaping late migration can leave a new token installed.
            $this->failures->clearAuthentication($request);
            $event->setResponse($this->failures->onAuthenticationFailure($request, $failure));

            return;
        }
        $status = $failure instanceof HttpExceptionInterface ? $failure->getStatusCode() : 503;
        if ($status >= 500) {
            $status = 503;
            try {
                $this->failures->clearAuthentication($request);
            } catch (\Throwable) {
                // Token clearing precedes session invalidation, including storage failure.
            }
            try {
                $this->logger->error('Web authentication unavailable.', ['component' => 'authenticating']);
            } catch (\Throwable) {
            }
        }
        $event->setResponse(new Response(503 === $status ? 'Authentication unavailable.' : 'Invalid request.', $status, ['Cache-Control' => 'no-store']));
    }
}
