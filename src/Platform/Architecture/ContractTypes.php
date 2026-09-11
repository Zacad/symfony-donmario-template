<?php

declare(strict_types=1);

namespace App\Platform\Architecture;

/** Shared public-data vocabulary for source, dependency and container checks. */
final class ContractTypes
{
    private const string NAME = '[A-Z][A-Za-z0-9]*';

    public const string IMMUTABLE_PATTERN = '~^(?:DateTimeImmutable|Symfony\\\\Component\\\\Uid\\\\Uuid)$~D';

    public static function isPublic(string $class): bool
    {
        return self::isApplicationData($class) || self::isEventData($class);
    }

    public static function isApplicationData(string $class): bool
    {
        return 1 === preg_match(self::applicationPattern(), $class);
    }

    public static function isEventData(string $class): bool
    {
        return 1 === preg_match(self::eventPattern(), $class);
    }

    /** Reserved data locations/names, including malformed and obsolete layouts. */
    public static function isDataCandidate(string $class): bool
    {
        return 'Contract' === ModuleMap::layer($class)
            || ('Application' === ModuleMap::layer($class) && 1 === preg_match('/(?:Command|Query|Result)$/D', $class));
    }

    public static function applicationPattern(?string $module = null): string
    {
        return '~^'.self::modulePattern($module).'Application\\\\'.self::NAME.'\\\\'.self::NAME.'(?:Command|Query|Result)$~D';
    }

    public static function eventPattern(?string $module = null): string
    {
        return '~^'.self::modulePattern($module).'Contract\\\\Event\\\\'.self::NAME.'$~D';
    }

    public static function isImmutable(string $class): bool
    {
        return 1 === preg_match(self::IMMUTABLE_PATTERN, $class);
    }

    private static function modulePattern(?string $module): string
    {
        return null === $module ? 'App\\\\Module\\\\'.self::NAME.'ing\\\\' : preg_quote('App\\Module\\'.$module.'\\', '~');
    }
}
