<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Symfony\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

#[AsEventListener(event: ResponseEvent::class)]
final readonly class AuthenticationResponseListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!preg_match('{^/(?:login|logout|account)(?:/|$)}D', rawurldecode($request->getPathInfo()))) {
            return;
        }
        $event->getResponse()->headers->set('Cache-Control', 'no-store');
        $event->getResponse()->headers->set('Referrer-Policy', 'no-referrer');
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->remove(SecurityRequestAttributes::LAST_USERNAME);
            $request->getSession()->remove(SecurityRequestAttributes::AUTHENTICATION_ERROR);
        }
    }
}
