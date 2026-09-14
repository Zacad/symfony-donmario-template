<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Symfony\EventListener;

use App\Module\Authenticating\Domain\EmailAddress;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

// Native LoginThrottlingListener consumes the badge at priority 2080.
#[AsEventListener(event: CheckPassportEvent::class, dispatcher: 'security.event_dispatcher.api_login', priority: 2100)]
final readonly class JsonLoginIdentifierListener
{
    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        $badge = $passport->getBadge(UserBadge::class);
        if (!$badge instanceof UserBadge) {
            return;
        }
        $identifier = $badge->getUserIdentifier();
        try {
            $identifier = EmailAddress::normalize($identifier);
        } catch (\InvalidArgumentException) {
            $identifier = strtolower(trim($identifier));
        }
        $passport->addBadge(new UserBadge($identifier, $badge->getUserLoader(), $badge->getAttributes()));
    }
}
