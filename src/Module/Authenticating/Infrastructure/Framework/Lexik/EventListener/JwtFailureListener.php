<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Lexik\EventListener;

use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\ApiAuthenticationFailureHandler;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTFailureEventInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: Events::JWT_INVALID)]
#[AsEventListener(event: Events::JWT_EXPIRED)]
#[AsEventListener(event: Events::JWT_NOT_FOUND)]
final readonly class JwtFailureListener
{
    public function __construct(private ApiAuthenticationFailureHandler $failures)
    {
    }

    public function __invoke(JWTFailureEventInterface $event): void
    {
        $event->setResponse($this->failures->respond(401));
    }
}
