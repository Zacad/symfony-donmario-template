<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Symfony\EventListener;

use App\Module\Authenticating\Domain\EmailAddress;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

#[AsEventListener(event: RequestEvent::class, priority: 64)]
final readonly class AuthenticationRequestListener
{
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = rawurldecode($request->getPathInfo());
        if (!preg_match('{^/(?:login|logout|account)(?:/|$)}D', $path)) {
            return;
        }

        if (!\in_array($path, ['/login', '/logout', '/account'], true) || $path !== $request->getPathInfo()) {
            $event->setResponse($this->error(404));

            return;
        }
        $methods = match ($path) {
            '/login' => ['GET', 'HEAD', 'POST'],
            '/logout' => ['POST'],
            default => ['GET', 'HEAD'],
        };
        if (!\in_array($request->getMethod(), $methods, true)) {
            $event->setResponse(new Response('Method not allowed.', 405, ['Allow' => implode(', ', $methods), 'Cache-Control' => 'no-store']));

            return;
        }
        if (!$this->validQuery($request)) {
            $event->setResponse($this->error(400));

            return;
        }
        // User-selected redirect destinations never enter native login/logout.
        $request->query->replace([]);
        if (!$request->isMethod('POST')) {
            return;
        }
        if ('application/x-www-form-urlencoded' !== strtolower(trim(explode(';', $request->headers->get('Content-Type') ?? '')[0]))) {
            $event->setResponse($this->error(415));

            return;
        }
        $stream = $request->getContent(true);
        $body = \is_resource($stream) ? stream_get_contents($stream, 16385) : false;
        if (false === $body) {
            $event->setResponse($this->error(400));

            return;
        }
        if (\strlen($body) > 16384) {
            $event->setResponse($this->error(413));

            return;
        }
        $limits = '/login' === $path
            ? ['_username' => 254, '_password' => 4096, '_csrf_token' => 512, '_target_path' => 512, '_failure_path' => 512]
            : ['_csrf_token' => 512, '_target_path' => 512, '_failure_path' => 512];
        $fields = [];
        foreach ('' === $body ? [] : explode('&', $body) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            $value = urldecode($value);
            if (!isset($limits[$name]) || isset($fields[$name]) || \strlen($value) > $limits[$name]
                || false !== strpbrk($value, "\0\r\n") || !mb_check_encoding($value, 'UTF-8')) {
                $event->setResponse($this->error(400));

                return;
            }
            $fields[$name] = $value;
        }
        if ('/login' === $path) {
            try {
                $fields['_username'] = EmailAddress::normalize($fields['_username'] ?? '');
            } catch (\InvalidArgumentException) {
                // Native authentication still gives the same generic failure for
                // syntactically invalid and unknown (bounded) email identifiers.
                $fields['_username'] = strtolower(trim($fields['_username'] ?? ''));
            }
        }
        unset($fields['_target_path'], $fields['_failure_path']);
        $request->request->replace($fields);
    }

    private function validQuery(Request $request): bool
    {
        $query = $request->server->get('QUERY_STRING', '');
        if (!\is_string($query) || \strlen($query) > 2048) {
            return false;
        }
        foreach ($request->query->all() as $name => $value) {
            if (!\in_array($name, ['_target_path', '_failure_path'], true) || !\is_string($value) || \strlen($value) > 512) {
                return false;
            }
        }

        return true;
    }

    private function error(int $status): Response
    {
        return new Response(match ($status) {
            404 => 'Not found.',
            413 => 'Request too large.',
            415 => 'Unsupported content type.',
            default => 'Invalid request.',
        }, $status, ['Cache-Control' => 'no-store']);
    }
}
