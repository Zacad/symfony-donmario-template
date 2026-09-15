<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Collections;

use App\Tools\Architecture\SourceRules;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final readonly class CollectionsFixture
{
    public const string MIGRATION = 'App\\Module\\CollectionChecking\\Resources\\migrations\\Version20260914000100';
    public string $projectDir;
    private Filesystem $filesystem;

    public function __construct()
    {
        $this->projectDir = sys_get_temp_dir().'/collections-'.bin2hex(random_bytes(10));
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
        foreach ((new Finder())->files()->in(__DIR__.'/Module')->name('*.fixture') as $file) {
            $this->write('src/Module/CollectionChecking/'.substr($file->getRelativePathname(), 0, -8), $file->getContents());
        }
        foreach (['bootstrap', 'console', 'offline', 'runtime', 'deptrac'] as $script) {
            $this->write($script.'.php', str_replace('__SOURCE_ROOT__', $source, $this->filesystem->readFile(__DIR__.'/'.$script.'.php.fixture')));
        }
        foreach (['transactions.txt', 'observations.jsonl', 'flush.jsonl'] as $file) {
            $this->write($file, '');
        }
        $prototype = $this->yaml('src/Module/TaskTracking/Resources/config/services.yaml')['services'];
        if (!\is_array($prototype)) {
            throw new \LogicException('Expected module services.');
        }
        $module = 'App\\Module\\CollectionChecking\\';
        $this->writeYaml('src/Module/CollectionChecking/Resources/config/services.yaml', ['services' => [
            '_defaults' => $prototype['_defaults'],
            $module => $prototype['App\\Module\\TaskTracking\\'],
            $module.'Domain\\ObservationRepository' => ['alias' => $module.'Infrastructure\\Persistence\\DoctrineObservationRepository'],
            $module.'Infrastructure\\Persistence\\DoctrineObservationRepository' => ['arguments' => ['$fixtureRoot' => $this->projectDir]],
        ]]);
        $this->writeYaml('config/packages/collections.yaml', [
            'framework' => ['validation' => ['mapping' => ['paths' => [
                '%kernel.project_dir%/src/Module/CollectionChecking/Resources/config/validation.yaml',
            ]]]],
            'doctrine_migrations' => ['migrations_paths' => [
                $module.'Resources\\migrations' => '%kernel.project_dir%/src/Module/CollectionChecking/Resources/migrations',
            ]],
        ]);
        $root = $this->yaml('config/services.yaml');
        if (!\is_array($root['services'])) {
            throw new \LogicException('Expected root services.');
        }
        foreach (['commands' => 'App\\Platform\\Messaging\\CommandBus', 'queries' => 'App\\Platform\\Messaging\\QueryBus'] as $alias => $service) {
            $root['services']['test.collections.'.$alias] = ['alias' => $service, 'public' => true];
        }
        $root['services'][TransactionLogger::class] = ['arguments' => ['$fixtureRoot' => $this->projectDir]];
        $root['services']['test.collections.driver_logging'] = [
            'class' => 'Doctrine\\DBAL\\Logging\\Middleware',
            'arguments' => ['@'.TransactionLogger::class],
            'tags' => [['name' => 'doctrine.middleware', 'connection' => 'default']],
        ];
        $this->writeYaml('config/services.yaml', $root);
    }

    /** @return list<string> */
    public function sourceViolations(): array
    {
        return (new SourceRules())->violations($this->projectDir);
    }

    /** @param list<string> $arguments */
    public function process(array $arguments): Process
    {
        return new Process([PHP_BINARY, ...$arguments], $this->projectDir, ['EVENT_TRANSPORT_DSN' => 'sync://'], timeout: 90);
    }

    /** @param list<string> $arguments */
    public function console(array $arguments): Process
    {
        $process = $this->process(['console.php', '--no-interaction', '--no-ansi', ...$arguments]);
        $process->run();

        return $process;
    }

    public function read(string $path): string
    {
        return $this->filesystem->readFile($this->projectDir.'/'.$path);
    }

    public function remove(): void
    {
        $this->filesystem->remove($this->projectDir);
    }

    private function write(string $path, string $contents): void
    {
        $this->filesystem->dumpFile($this->projectDir.'/'.$path, $contents);
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
