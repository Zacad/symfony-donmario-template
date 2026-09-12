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

    public static function isAnyEventData(string $class): bool
    {
        return null !== self::eventCategory($class);
    }

    /**
     * Namespace classification alone must not authorize a runtime event object.
     *
     * @template T of object
     *
     * @param \ReflectionClass<T> $class
     */
    public static function isConcreteEvent(\ReflectionClass $class): bool
    {
        $category = self::eventCategory($class->name);
        $parent = $class->getParentClass();

        return null !== $category && false !== $parent && $category === $parent->name
            && $class->isFinal() && $class->isReadOnly() && !$class->isAbstract();
    }

    /** The exact direct parent required by each concrete event location. */
    public static function eventCategory(string $class): ?string
    {
        foreach (['Domain', 'Application', 'Infrastructure'] as $category) {
            if (1 === preg_match(self::categoryPattern($category), $class)) {
                return 'App\\Platform\\Event\\'.$category.'Event';
            }
        }

        return null;
    }

    public static function isEventPrimitive(string $class): bool
    {
        return in_array($class, self::eventPrimitives(), true);
    }

    /** Opt-in Domain implementation support, never public data or an event category. */
    public static function isEventRecordingSupport(string $class): bool
    {
        return in_array($class, [
            'App\\Platform\\Event\\Recording\\RecordsDomainEvents',
            'App\\Platform\\Event\\Recording\\RecordsDomainEventsTrait',
        ], true);
    }

    /** @return list<string> */
    public static function eventPrimitives(): array
    {
        return array_map(static fn (string $name): string => 'App\\Platform\\Event\\'.$name.'Event', ['Base', 'Domain', 'Application', 'Infrastructure']);
    }

    public static function isOwnEventListener(string $class): bool
    {
        return 1 === preg_match(self::listenerPattern(), $class);
    }

    public static function listenerPattern(?string $module = null): string
    {
        return '~^'.self::modulePattern($module).'Infrastructure\\\\EventListener\\\\'.self::NAME.'Listener$~D';
    }

    public static function frameworkListenerPattern(?string $module = null): string
    {
        return '~^'.self::modulePattern($module).'Infrastructure\\\\Framework\\\\'.self::NAME.'\\\\EventListener\\\\'.self::NAME.'$~D';
    }

    public static function messageKind(string $class): ?string
    {
        if (self::isEventData($class)) {
            return 'event';
        }
        if (!self::isApplicationData($class)) {
            return null;
        }

        return match (true) {
            str_ends_with($class, 'Command') => 'command',
            str_ends_with($class, 'Query') => 'query',
            default => null,
        };
    }

    /** Reserved data locations/names, including malformed and obsolete layouts. */
    public static function isDataCandidate(string $class): bool
    {
        return 'Contract' === ModuleMap::layer($class)
            || self::isEventPrimitive($class)
            || ('Application' === ModuleMap::layer($class) && 1 === preg_match('/(?:Command|Query|Result|Event)$/D', $class))
            || (null !== ModuleMap::owner($class) && (str_contains($class, '\\Event\\') || str_ends_with($class, 'Event')));
    }

    public static function applicationPattern(?string $module = null): string
    {
        return '~^'.self::modulePattern($module).'Application\\\\'.self::NAME.'\\\\'.self::NAME.'(?:Command|Query|Result)$~D';
    }

    public static function eventPattern(?string $module = null): string
    {
        return self::categoryPattern('Application', $module);
    }

    public static function categoryPattern(string $category, ?string $module = null): string
    {
        return '~^'.self::modulePattern($module).$category.'\\\\'.('Application' === $category ? self::NAME : 'Event').'\\\\'.self::NAME.'Event$~D';
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
