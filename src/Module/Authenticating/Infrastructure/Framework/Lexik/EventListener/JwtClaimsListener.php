<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Lexik\EventListener;

use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

final readonly class JwtClaimsListener
{
    private const array CLAIMS = ['sub', 'iss', 'aud', 'iat', 'nbf', 'exp'];

    public function __construct(
        #[Autowire(param: 'authenticating.jwt.issuer')] private string $issuer,
        #[Autowire(param: 'authenticating.jwt.audience')] private string $audience,
    ) {
    }

    #[AsEventListener(event: Events::JWT_CREATED)]
    public function onCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof AccountPrincipal) {
            throw new \LogicException('Unsupported account principal.');
        }
        $now = time();
        // Replace Lexik's default roles/identity payload rather than enriching it.
        $event->setData([
            'sub' => $user->getUserIdentifier(),
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $now,
            'nbf' => new \DateTimeImmutable('@'.$now),
            'exp' => $now + 900,
        ]);
        $event->setHeader(['typ' => 'JWT']);
    }

    #[AsEventListener(event: Events::JWT_DECODED)]
    public function onDecoded(JWTDecodedEvent $event): void
    {
        // Lexik/Lcobucci have already checked the configured signature and dates.
        // Dates here are native normalized timestamps, not original JSON wire types.
        $claims = $event->getPayload();
        $now = time();
        if (\count($claims) !== \count(self::CLAIMS) || [] !== array_diff(self::CLAIMS, array_keys($claims))
            || !\is_string($claims['sub']) || !Uuid::isValid($claims['sub'], Uuid::FORMAT_RFC_4122)
            || $this->issuer !== $claims['iss'] || [$this->audience] !== $claims['aud']
            || !\is_int($claims['iat']) || !\is_int($claims['nbf']) || !\is_int($claims['exp'])
            || $claims['iat'] < 0 || $claims['iat'] > $now || $claims['nbf'] !== $claims['iat']
            || $claims['exp'] <= $now || $claims['exp'] - $claims['iat'] !== 900) {
            $event->markAsInvalid();
        }
    }
}
