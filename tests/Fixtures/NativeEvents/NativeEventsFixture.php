<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\NativeEvents;

use App\Tools\Architecture\SourceRules;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final readonly class NativeEventsFixture
{
    public const string MIGRATION = 'App\\Module\\NativeObserving\\Resources\\migrations\\Version20260913000100';
    public const int LOCK = 751305;
    public string $projectDir;
    private Filesystem $filesystem;

    public function __construct()
    {
        $this->projectDir = sys_get_temp_dir().'/native-events-'.bin2hex(random_bytes(10));
        $this->filesystem = new Filesystem();
    }

    public function initialize(): void
    {
        $source = \dirname(__DIR__, 3);
        $this->filesystem->mkdir($this->projectDir, 0700);
        foreach (['src', 'config', 'templates', 'assets', 'translations', 'public'] as $directory) {
            if (is_dir($source.'/'.$directory)) {
                $this->filesystem->mirror($source.'/'.$directory, $this->projectDir.'/'.$directory);
            }
        }
        foreach (['composer.json', 'importmap.php'] as $file) {
            $this->filesystem->copy($source.'/'.$file, $this->projectDir.'/'.$file);
        }
        $this->filesystem->symlink($source.'/vendor', $this->projectDir.'/vendor');
        foreach ((new Finder())->files()->in(__DIR__.'/Module')->name('*.php.fixture') as $file) {
            $this->write('src/Module/NativeObserving/'.substr($file->getRelativePathname(), 0, -8), $file->getContents());
        }
        foreach (['bootstrap', 'console', 'http', 'held', 'deptrac'] as $script) {
            $this->write($script.'.php', str_replace('__SOURCE_ROOT__', $source, $this->filesystem->readFile(__DIR__.'/'.$script.'.php.fixture')));
        }
        $this->mode('success');
        $this->write('attempts.jsonl', '');
        $this->write('flush.jsonl', '');
        $prototype = $this->yaml('src/Module/TaskTracking/Resources/config/services.yaml')['services'];
        if (!\is_array($prototype)) {
            throw new \LogicException('Expected module services.');
        }
        $module = 'App\\Module\\NativeObserving\\';
        $moduleDefaults = $prototype['App\\Module\\TaskTracking\\'];
        if (!\is_array($moduleDefaults) || !\is_array($moduleDefaults['exclude'] ?? null)) {
            throw new \LogicException('Expected module resource exclusions.');
        }
        $exclusions = array_values(array_filter($moduleDefaults['exclude'], static fn (mixed $exclusion): bool => '../../Infrastructure/Framework/Symfony/Security/TaskTrackingVoter.php' !== $exclusion));
        if (count($moduleDefaults['exclude']) - 1 !== count($exclusions)) {
            throw new \LogicException('Expected exactly one TaskTracking voter exclusion.');
        }
        $moduleDefaults['exclude'] = $exclusions;
        $this->writeYaml('src/Module/NativeObserving/Resources/config/services.yaml', ['services' => [
            '_defaults' => $prototype['_defaults'],
            $module => $moduleDefaults,
            $module.'Domain\\ObservationRepository' => ['alias' => $module.'Infrastructure\\Persistence\\DoctrineObservationRepository'],
            $module.'Infrastructure\\Persistence\\DoctrineObservationRepository' => ['arguments' => ['$fixtureRoot' => $this->projectDir]],
        ]]);
        $this->writeYaml('config/packages/native_verification.yaml', [
            'doctrine' => ['orm' => ['entity_managers' => ['default' => ['mappings' => ['NativeObserving' => [
                'type' => 'attribute', 'is_bundle' => false,
                'dir' => '%kernel.project_dir%/src/Module/NativeObserving/Domain',
                'prefix' => 'App\\Module\\NativeObserving\\Domain',
            ]]]]]],
            'doctrine_migrations' => ['migrations_paths' => [
                'App\\Module\\NativeObserving\\Resources\\migrations' => '%kernel.project_dir%/src/Module/NativeObserving/Resources/migrations',
            ]],
        ]);
        $root = $this->yaml('config/services.yaml');
        if (!\is_array($root['services'])) {
            throw new \LogicException('Expected root services.');
        }
        foreach (['commands' => 'App\\Platform\\Messaging\\CommandBus', 'queries' => 'App\\Platform\\Messaging\\QueryBus', 'events' => 'App\\Platform\\Messaging\\EventBus', 'observations' => $module.'Domain\\ObservationRepository'] as $alias => $service) {
            $root['services']['test.native.'.$alias] = ['alias' => $service, 'public' => true];
        }
        $this->writeYaml('config/services.yaml', $root);
    }

    /** @return list<string> */
    public function sourceViolations(): array
    {
        return (new SourceRules())->violations($this->projectDir);
    }

    public function mode(string $mode): void
    {
        $this->write('mode.txt', $mode);
    }

    public function write(string $path, string $contents): void
    {
        $this->filesystem->dumpFile($this->projectDir.'/'.$path, $contents);
    }

    public function read(string $path): string
    {
        return $this->filesystem->readFile($this->projectDir.'/'.$path);
    }

    /** @param list<string> $arguments */
    public function process(array $arguments, ?string $dsn = null): Process
    {
        return new Process([PHP_BINARY, ...$arguments], $this->projectDir, null === $dsn ? null : ['EVENT_TRANSPORT_DSN' => $dsn], timeout: 45);
    }

    /** @param list<string> $arguments */
    public function console(array $arguments, ?string $dsn = null): Process
    {
        $process = $this->process(['console.php', '--no-interaction', '--no-ansi', ...$arguments], $dsn);
        $process->run();

        return $process;
    }

    public function worker(int $limit): Process
    {
        $process = $this->process(['console.php', 'messenger:consume', 'events', '--limit='.$limit, '--time-limit=20', '--sleep=0.01', '--no-interaction', '--no-ansi'], 'doctrine://default');
        $process->start();

        return $process;
    }

    public function remove(): void
    {
        $this->filesystem->remove($this->projectDir);
    }

    public function clearCache(): void
    {
        // This disposable kernel has fixed cache/build paths. Discard its compiled
        // container explicitly when changing its physical listener inventory.
        $this->filesystem->remove($this->projectDir.'/var/cache');
    }

    /** @param array<string, mixed> $data */
    private function writeYaml(string $path, array $data): void
    {
        $this->write($path, Yaml::dump($data, 12));
    }

    /** @return array<string, mixed> */
    private function yaml(string $path): array
    {
        $data = Yaml::parseFile($this->projectDir.'/'.$path);
        if (!\is_array($data)) {
            throw new \LogicException('Expected YAML mapping.');
        }

        /** @var array<string, mixed> $mapping */
        $mapping = $data;

        return $mapping;
    }
}
