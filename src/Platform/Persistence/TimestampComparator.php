<?php

declare(strict_types=1);

namespace App\Platform\Persistence;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;

final class TimestampComparator implements Comparator
{
    public function compare(Version $a, Version $b): int
    {
        return strcmp(self::timestamp((string) $a), self::timestamp((string) $b));
    }

    public static function timestamp(string $version): string
    {
        if (!preg_match('/\\\\Version([0-9]{14})$/D', $version, $matches)) {
            throw new \LogicException('migration.version: '.$version.' requires VersionYYYYMMDDHHMMSS.');
        }
        $date = \DateTimeImmutable::createFromFormat('!YmdHis', $matches[1], new \DateTimeZone('UTC'));
        if (false === $date || $date->format('YmdHis') !== $matches[1]) {
            throw new \LogicException('migration.version: '.$version.' has an invalid UTC timestamp.');
        }

        return $matches[1];
    }
}
