<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Authenticating;

/** Only the isolated runner volume retains this private cross-phase evidence. */
final class JwtState
{
    /** @param array<string, string> $state */
    public static function save(#[\SensitiveParameter] array $state, string $name = 'state'): void
    {
        $mask = umask(0077);
        try {
            if (false === file_put_contents(self::path($name), json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX)) {
                throw new \RuntimeException('Cannot save private JWT phase state.');
            }
        } finally {
            umask($mask);
        }
    }

    /** @return array<string, string> */
    public static function load(string $name = 'state'): array
    {
        $data = @file_get_contents(self::path($name));
        if (false === $data) {
            throw new \RuntimeException('Missing private JWT phase state.');
        }
        $state = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($state)) {
            throw new \RuntimeException('Invalid JWT phase state.');
        }
        foreach ($state as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new \RuntimeException('Invalid JWT phase field.');
            }
        }

        return $state;
    }

    private static function path(string $name): string
    {
        if ('test' !== getenv('APP_ENV') || !in_array($name, ['state', 'throttle'], true)) {
            throw new \RuntimeException('JWT phase fixtures require the isolated test runtime.');
        }

        return dirname(__DIR__, 3).'/var/authenticating-jwt-'.$name.'.json';
    }
}
