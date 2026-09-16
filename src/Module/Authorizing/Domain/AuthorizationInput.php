<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain;

use Symfony\Component\Uid\Uuid;

final class AuthorizationInput
{
    public static function key(string $key): void
    {
        if (1 !== preg_match('/\A[a-z0-9._-]{1,64}\z/D', $key)) {
            throw new InvalidAuthorizationInput();
        }
    }

    public static function scope(string $scope, ?string $resourceType, ?Uuid $resourceId): void
    {
        if ('global' === $scope && null === $resourceType && null === $resourceId) {
            return;
        }
        if ('resource' !== $scope || null === $resourceType || null === $resourceId) {
            throw new InvalidAuthorizationInput();
        }

        self::key($resourceType);
    }
}
