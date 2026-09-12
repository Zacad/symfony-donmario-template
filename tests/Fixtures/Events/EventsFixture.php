<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Events;

use App\Tools\Architecture\SourceRules;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** Generates a consumer app in writable /tmp; source and vendor remain read-only. */
final readonly class EventsFixture
{
    public string $projectDir;
    private Filesystem $filesystem;

    public function __construct(public string $scenario)
    {
        $this->projectDir = sys_get_temp_dir().'/event-verification-'.bin2hex(random_bytes(12));
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
            $this->write('src/Module/EventObserving/'.substr($file->getRelativePathname(), 0, -8), $file->getContents());
        }
        $this->write('src/Module/TaskTracking/Infrastructure/Testing/ObservedTaskRepository.php', $this->filesystem->readFile(__DIR__.'/TaskTracking/ObservedTaskRepository.php.fixture'));
        foreach (['bootstrap', 'console', 'http', 'scenario', 'deptrac'] as $script) {
            $this->write($script.'.php', str_replace('__SOURCE_ROOT__', $source, $this->filesystem->readFile(__DIR__.'/'.$script.'.php.fixture')));
        }
        $this->write('scenario.txt', $this->scenario);
        $this->write('probe.jsonl', '');

        $prototype = $this->yaml('src/Module/TaskTracking/Resources/config/services.yaml');
        $services = $prototype['services'];
        if (!\is_array($services)) {
            throw new \LogicException('Expected module service definitions.');
        }
        $module = 'App\\Module\\EventObserving\\';
        $observing = [
            '_defaults' => $services['_defaults'],
            $module => $services['App\\Module\\TaskTracking\\'],
            $module.'Domain\\ObservationRepository' => ['alias' => $module.'Infrastructure\\Persistence\\DoctrineObservationRepository'],
            $module.'Infrastructure\\Persistence\\DoctrineObservationRepository' => ['arguments' => ['$scenario' => $this->scenario, '$probePath' => $this->projectDir.'/probe.jsonl']],
        ];
        foreach (['CreateRequiredTasks', 'EmitEvents', 'ContinueChain'] as $useCase) {
            $observing[$module.'Application\\'.$useCase.'\\'.$useCase.'Handler'] = ['arguments' => ['$scenario' => $this->scenario]];
        }
        $this->writeYaml('src/Module/EventObserving/Resources/config/services.yaml', ['services' => $observing]);
        $services['App\\Module\\TaskTracking\\Domain\\TaskRepository'] = ['alias' => 'App\\Module\\TaskTracking\\Infrastructure\\Testing\\ObservedTaskRepository'];
        $services['App\\Module\\TaskTracking\\Infrastructure\\Testing\\ObservedTaskRepository'] = ['arguments' => ['$probePath' => $this->projectDir.'/probe.jsonl']];
        $this->writeYaml('src/Module/TaskTracking/Resources/config/services.yaml', ['services' => $services]);

        // Extend, rather than replace, the production Doctrine configuration.
        $this->writeYaml('config/packages/event_verification.yaml', [
            'doctrine' => ['orm' => ['entity_managers' => ['default' => ['mappings' => ['EventObserving' => [
                'type' => 'attribute', 'is_bundle' => false,
                'dir' => '%kernel.project_dir%/src/Module/EventObserving/Domain',
                'prefix' => 'App\\Module\\EventObserving\\Domain',
            ]]]]]],
            'doctrine_migrations' => ['migrations_paths' => [
                'App\\Module\\EventObserving\\Resources\\migrations' => '%kernel.project_dir%/src/Module/EventObserving/Resources/migrations',
            ]],
        ]);
        $root = $this->yaml('config/services.yaml');
        if (!\is_array($root['services'])) {
            throw new \LogicException('Expected root services.');
        }
        foreach ([
            'commands' => 'App\\Platform\\Messaging\\CommandBus',
            'queries' => 'App\\Platform\\Messaging\\QueryBus',
            'recorder' => 'App\\Platform\\Messaging\\ApplicationEventRecorder',
            'tasks' => 'App\\Module\\TaskTracking\\Domain\\TaskRepository',
            'observations' => $module.'Domain\\ObservationRepository',
        ] as $name => $class) {
            $root['services']['test.events.'.$name] = ['alias' => $class, 'public' => true];
        }
        $this->writeYaml('config/services.yaml', $root);
        if (\in_array($this->scenario, ['late-failure', 'multiple-failure'], true)) {
            $listener = 'late-failure' === $this->scenario ? 'Middle' : 'Last';
            $label = strtolower($listener);
            $path = 'src/Module/EventObserving/Infrastructure/EventListener/'.$listener.'TaskListener.php';
            $contents = $this->filesystem->readFile($this->projectDir.'/'.$path);
            $contents = str_replace('use App\\Platform\\Messaging\\CommandBus;', "use App\\Platform\\Messaging\\CommandBus;\nuse App\\Module\\TaskTracking\\Application\\CreateTask\\CreateTaskCommand;", $contents);
            $contents = str_replace("new RecordTaskCreationCommand(\$event->taskId, '$label'));", "new RecordTaskCreationCommand(\$event->taskId, '$label'));\n        \$this->commands->dispatch(new CreateTaskCommand(str_repeat('EVENT_PRIVATE_FAILURE_CANARY', 10)));", $contents);
            $this->write($path, $contents);
        }
        if ('constructor-failure' === $this->scenario) {
            $path = 'src/Module/EventObserving/Infrastructure/EventListener/MiddleTaskListener.php';
            $contents = $this->filesystem->readFile($this->projectDir.'/'.$path);
            $contents = str_replace('use Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler;', "use Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler;\nuse Symfony\\Component\\Uid\\Uuid;", $contents);
            $contents = str_replace("public function __construct(private CommandBus \$commands)\n    {\n    }", "public function __construct(private CommandBus \$commands)\n    {\n        Uuid::fromString('EVENT_PRIVATE_FAILURE_CANARY');\n    }", $contents, $replacements);
            if (1 !== $replacements) {
                throw new \LogicException('Expected exactly one listener constructor for the failure fixture.');
            }
            $this->write($path, $contents);
        }
        if ('internal-fact' === $this->scenario) {
            // A second Domain fact must not be mistaken for another creation.
            $this->write('src/Module/TaskTracking/Domain/Event/TaskInspectedEvent.php', $this->filesystem->readFile(__DIR__.'/TaskTracking/TaskInspectedEvent.php.fixture'));
            $path = 'src/Module/TaskTracking/Domain/Task.php';
            $contents = $this->filesystem->readFile($this->projectDir.'/'.$path);
            $recording = '$this->recordDomainEvent(new TaskCreatedEvent($this->id));';
            $contents = str_replace($recording, $recording."\n        \$this->recordDomainEvent(new \\App\\Module\\TaskTracking\\Domain\\Event\\TaskInspectedEvent(\$this->id));", $contents, $replacements);
            if (1 !== $replacements) {
                throw new \LogicException('Expected exactly one Task creation fact for selective-publication verification.');
            }
            $this->write($path, $contents);
        }
    }

    /** @return list<string> */
    public function sourceViolations(): array
    {
        return (new SourceRules())->violations($this->projectDir);
    }

    /** @param list<string> $arguments */
    public function console(array $arguments): Process
    {
        return $this->run(['console.php', '--no-interaction', '--no-ansi', ...$arguments]);
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, ...$arguments], $this->projectDir, timeout: 60);
        $process->run();

        return $process;
    }

    public function remove(): void
    {
        $this->filesystem->remove($this->projectDir);
    }

    public function readProbe(): string
    {
        return $this->filesystem->readFile($this->projectDir.'/probe.jsonl');
    }

    private function write(string $path, string $contents): void
    {
        $this->filesystem->dumpFile($this->projectDir.'/'.$path, $contents);
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

    /** @param array<string, mixed> $data */
    private function writeYaml(string $path, array $data): void
    {
        $this->write($path, Yaml::dump($data, 12));
    }
}
