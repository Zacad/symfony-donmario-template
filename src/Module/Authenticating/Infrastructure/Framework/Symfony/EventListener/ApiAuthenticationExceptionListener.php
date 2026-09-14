<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Symfony\EventListener;

use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\ApiAuthenticationFailureHandler;
use Lcobucci\JWT\Token\Parser;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

#[AsEventListener(event: ExceptionEvent::class, priority: 2048)]
final readonly class ApiAuthenticationExceptionListener
{
    public function __construct(private ApiAuthenticationFailureHandler $failures, private LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!preg_match('{^/api(?:/|$)}D', rawurldecode($event->getRequest()->getPathInfo()))) {
            return;
        }
        $failure = $event->getThrowable();
        // Lcobucci 5.6 rejects null/array/bool NumericDates with TypeError;
        // Lexik 3.2 wraps Exception only. Classify this exact parser boundary
        // without parsing tokens ourselves or hiding unrelated runtime failures.
        $origin = $failure->getTrace()[0] ?? [];
        if ($failure instanceof \TypeError && Parser::class === ($origin['class'] ?? null)
            && 'convertDate' === $origin['function']) {
            $event->setResponse($this->failures->respond(401));

            return;
        }
        if ($failure instanceof AuthenticationException) {
            $event->setResponse($this->failures->onAuthenticationFailure($event->getRequest(), $failure));

            return;
        }
        if ($failure instanceof AccessDeniedException) {
            // Native Security selects the entry point for anonymous requests.
            return;
        }
        $status = $failure instanceof HttpExceptionInterface ? $failure->getStatusCode() : 503;
        if ($status >= 500) {
            $status = 503;
            try {
                $this->logger->error('API authentication unavailable.', ['component' => 'authenticating']);
            } catch (\Throwable) {
            }
        }
        $event->setResponse($this->failures->respond($status));
    }
}
