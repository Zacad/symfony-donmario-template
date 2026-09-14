<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Symfony\EventListener;

use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\ApiAuthenticationFailureHandler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

#[AsEventListener(event: RequestEvent::class, priority: 64)]
final readonly class ApiAuthenticationRequestListener
{
    public function __construct(private ApiAuthenticationFailureHandler $failures)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = rawurldecode($request->getPathInfo());
        if (!$event->isMainRequest() || !preg_match('{^/api(?:/|$)}D', $path)) {
            return;
        }
        if ($path !== $request->getPathInfo() || str_starts_with($path, '/api/login/')) {
            $event->setResponse($this->failures->respond(404));

            return;
        }
        $authorization = $request->headers->get('Authorization') ?? '';
        if (\strlen($authorization) > 8192 + 7) {
            $event->setResponse($this->failures->respond(401));

            return;
        }
        if ('/api/login' !== $path) {
            return;
        }
        if (!$request->isMethod('POST')) {
            $response = $this->failures->respond(405);
            $response->headers->set('Allow', 'POST');
            $event->setResponse($response);

            return;
        }
        if ('' !== $request->server->get('QUERY_STRING', '')) {
            $event->setResponse($this->failures->respond(400));

            return;
        }
        if ('application/json' !== strtolower(trim(explode(';', $request->headers->get('Content-Type') ?? '')[0]))) {
            $event->setResponse($this->failures->respond(415));

            return;
        }
        // Cache the bounded content for native json_login; do not consume its stream.
        $body = $request->getContent();
        if (\strlen($body) > 16384) {
            $event->setResponse($this->failures->respond(413));

            return;
        }
        try {
            $data = json_decode($body, false, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $event->setResponse($this->failures->respond(400));

            return;
        }
        if (!$data instanceof \stdClass || 2 !== \count(get_object_vars($data))
            || !isset($data->email, $data->password) || !\is_string($data->email) || !\is_string($data->password)
            || \strlen($data->email) > 254 || \strlen($data->password) > 4096
            || false !== strpbrk($data->email.$data->password, "\0\r\n")) {
            $event->setResponse($this->failures->respond(400));
        }
    }
}
