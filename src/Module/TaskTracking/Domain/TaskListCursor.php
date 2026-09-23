<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Domain;

use Symfony\Component\Uid\Uuid;

/** Pagination data only: admission and visibility must be checked on every page. */
final class TaskListCursor
{
    public static function encode(?Uuid $ownerAccountId, Uuid $after): string
    {
        return rtrim(strtr(base64_encode('2.tasks|'.($ownerAccountId?->toRfc4122() ?? '-').'|'.$after->toRfc4122()), '+/', '-_'), '=');
    }

    public static function decode(?string $cursor, ?Uuid $ownerAccountId): ?Uuid
    {
        if (null === $cursor) {
            return null;
        }
        if (strlen($cursor) > 512 || 1 !== preg_match('/\A[A-Za-z0-9_-]+\z/D', $cursor)) {
            throw new InvalidTaskInput('Invalid task cursor.');
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = false === $decoded ? [] : explode('|', $decoded);
        if (3 !== count($parts) || '2.tasks' !== $parts[0] || ($ownerAccountId?->toRfc4122() ?? '-') !== $parts[1] || !Uuid::isValid($parts[2])) {
            throw new InvalidTaskInput('Invalid task cursor.');
        }

        $after = Uuid::fromString($parts[2]);
        if (self::encode($ownerAccountId, $after) !== $cursor) {
            throw new InvalidTaskInput('Invalid task cursor.');
        }

        return $after;
    }
}
