<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Symfony\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsEventListener(event: ResponseEvent::class)]
final readonly class ApiAuthenticationResponseListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (preg_match('{^/api(?:/|$)}D', rawurldecode($event->getRequest()->getPathInfo()))) {
            $event->getResponse()->headers->set('Cache-Control', 'no-store');
            $event->getResponse()->headers->set('Referrer-Policy', 'no-referrer');
        }
    }
}
