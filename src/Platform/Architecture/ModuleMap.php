<?php

declare(strict_types=1);

namespace App\Platform\Architecture;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Filesystem/name conventions only; this is not a runtime module registry. */
final readonly class ModuleMap
{
    public function __construct(#[Autowire('%kernel.project_dir%')] public string $projectDir)
    {
    }

    /** @return list<string> */
    public function modules(): array
    {
        $modules = [];
        foreach (glob($this->projectDir.'/src/Module/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $name = basename($directory);
            if (!preg_match('/^[A-Z][A-Za-z0-9]*ing$/D', $name)) {
                throw new \LogicException('module.name: '.$name.' must describe a responsibility ending in ing.');
            }
            $modules[] = $name;
        }
        sort($modules);

        return $modules;
    }

    public static function owner(string $class): ?string
    {
        return preg_match('/^App\\\\Module\\\\([^\\\\]+)\\\\/', $class, $matches) ? $matches[1] : null;
    }

    public static function prefix(string $module): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $module)).'_';
    }

    public static function layer(string $class): ?string
    {
        return preg_match('/^App\\\\Module\\\\[^\\\\]+\\\\([^\\\\]+)\\\\/', $class, $matches) ? $matches[1] : null;
    }

    public function path(string $module): string
    {
        return $this->projectDir.'/src/Module/'.$module;
    }
}
