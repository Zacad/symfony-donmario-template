<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Authenticating;

final class State
{
    /** @param array<string, string> $state */
    public static function save(#[\SensitiveParameter] array $state): void
    {
        $path = dirname(__DIR__, 3).'/var/authenticating-state.json';
        $mask = umask(0077);
        try {
            if (false === file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX)) {
                throw new \RuntimeException('Cannot save private authentication evidence.');
            }
        } finally {
            umask($mask);
        }
    }

    /** @return array<string, string> */
    public static function load(): array
    {
        $data = file_get_contents(dirname(__DIR__, 3).'/var/authenticating-state.json');
        if (false === $data) {
            throw new \RuntimeException('Missing authentication phase state.');
        }
        $state = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($state)) {
            throw new \RuntimeException('Invalid authentication phase state.');
        }
        foreach ($state as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new \RuntimeException('Invalid authentication phase data.');
            }
        }

        return $state;
    }
}
