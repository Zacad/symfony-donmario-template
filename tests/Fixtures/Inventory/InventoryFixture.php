<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Inventory;

use App\Platform\Architecture\ModuleMap;
use Composer\Autoload\ClassLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/** Isolated source trees; only generated copies of PHP templates are autoloadable. */
final readonly class InventoryFixture
{
    public string $projectDir;
    public string $module;
    public string $additionalModule;
    public string $platformService;
    private ModuleMap $modules;
    private ClassLoader $loader;
    private Filesystem $filesystem;
    private string $platformNamespace;

    private function __construct()
    {
        $token = bin2hex(random_bytes(12));
        $this->projectDir = '/tmp/module-inventory-'.$token;
        $this->module = 'Fixture'.$token.'Tracking';
        $this->additionalModule = 'Document'.$token.'Archiving';
        $this->platformNamespace = 'App\\Platform\\Inventory'.$token;
        $this->platformService = $this->platformNamespace.'\\PlatformService';
        $this->modules = new ModuleMap($this->projectDir);
        $this->loader = new ClassLoader();
        $this->filesystem = new Filesystem();
    }

    /** @param callable(self): void $test */
    public static function run(callable $test): void
    {
        $fixture = new self();
        try {
            $fixture->initialize();
            $test($fixture);
        } finally {
            $fixture->loader->unregister();
            $fixture->filesystem->remove($fixture->projectDir);
        }
    }

    public function addModule(string $module, string $label = 'module-local'): string
    {
        $root = $this->modules->path($module);
        $this->filesystem->mkdir([$root.'/Domain', $root.'/Infrastructure/Event']);
        $this->loader->addPsr4('App\\Module\\'.$module.'\\', $root);
        foreach (['ConfiguredService', 'Dependency'] as $class) {
            $this->write('src/Module/'.$module.'/Infrastructure/'.$class.'.php', str_replace(
                'App\\Module\\Inventorying',
                'App\\Module\\'.$module,
                $this->template($class.'.php.fixture'),
            ));
        }

        $id = 'App\\Module\\'.$module.'\\Infrastructure\\ConfiguredService';
        // Exercise the application's module prototype, with only the namespace
        // adapted and an ordinary module-owned service customization appended.
        $configuration = str_replace('App\\Module\\TaskTracking\\', 'App\\Module\\'.$module.'\\', $this->source('src/Module/TaskTracking/Resources/config/services.yaml'));
        $configuration .= "\n    $id:\n        bind:\n            string \$label: ".Yaml::dump($label)."\n";
        $this->write($this->configurationPath($module), $configuration);

        return $id;
    }

    public function omitService(string $module): void
    {
        $this->write($this->configurationPath($module), str_replace('App\\Module\\Inventorying', 'App\\Module\\'.$module, $this->template('services-omitted.yaml')));
    }

    public function addDomainService(string $module, string $override = ''): string
    {
        $this->write('src/Module/'.$module.'/Domain/Policy.php', str_replace('App\\Module\\Inventorying', 'App\\Module\\'.$module, $this->template('Policy.php.fixture')));
        $id = 'App\\Module\\'.$module.'\\Domain\\Policy';
        $path = $this->configurationPath($module);
        $this->write($path, $this->read($path)."\n    $id:\n        $override\n");

        return $id;
    }

    public function addController(string $module): string
    {
        $this->write('src/Module/'.$module.'/UI/Http/ProbeController.php', str_replace('App\\Module\\Inventorying', 'App\\Module\\'.$module, $this->template('ProbeController.php.fixture')));

        return 'App\\Module\\'.$module.'\\UI\\Http\\ProbeController';
    }

    public function addUseCase(string $module): string
    {
        $this->addDomainService($module);
        foreach (['LookupCommand', 'LookupQuery', 'LookupResult', 'LookupHandler'] as $class) {
            $this->write('src/Module/'.$module.'/Application/Lookup/'.$class.'.php', str_replace('App\\Module\\Inventorying', 'App\\Module\\'.$module, $this->template($class.'.php.fixture')));
        }
        $this->write('src/Module/'.$module.'/UI/Console/ProbeCommand.php', str_replace('App\\Module\\Inventorying', 'App\\Module\\'.$module, $this->template('ProbeCommand.php.fixture')));

        return 'App\\Module\\'.$module.'\\Application\\Lookup\\LookupHandler';
    }

    /** @return list<string> */
    public function addEvents(string $module): array
    {
        $classes = [];
        foreach (['Domain' => 'Event', 'Application' => 'Lookup', 'Infrastructure' => 'Event'] as $category => $directory) {
            $namespace = 'App\\Module\\'.$module.'\\'.$category.'\\'.$directory;
            $class = $namespace.'\\ObservedEvent';
            $this->write('src/Module/'.$module.'/'.$category.'/'.$directory.'/ObservedEvent.php', '<?php namespace '.$namespace.'; final readonly class ObservedEvent extends \\App\\Platform\\Event\\'.$category.'Event { public function __construct(public string $id) {} }');
            $classes[] = $class;
        }

        return $classes;
    }

    public function configurationPath(string $module): string
    {
        return 'src/Module/'.$module.'/Resources/config/services.yaml';
    }

    /** @return list<string> */
    public function addRecordingEntities(string $module): array
    {
        foreach (['RecordsDomainEvents', 'RecordsDomainEventsTrait'] as $support) {
            $path = 'src/Platform/Event/Recording/'.$support.'.php';
            $this->write($path, $this->source($path));
        }
        $classes = [];
        foreach (['FirstRecord', 'SecondRecord'] as $name) {
            $namespace = 'App\\Module\\'.$module.'\\Domain';
            $this->write('src/Module/'.$module.'/Domain/'.$name.'.php', '<?php namespace '.$namespace.'; '
                .'#[\\Doctrine\\ORM\\Mapping\\Entity] final class '.$name.' implements \\App\\Platform\\Event\\Recording\\RecordsDomainEvents { '
                .'use \\App\\Platform\\Event\\Recording\\RecordsDomainEventsTrait; '
                .'#[\\Doctrine\\ORM\\Mapping\\Id] #[\\Doctrine\\ORM\\Mapping\\Column] private int $id; }');
            $classes[] = $namespace.'\\'.$name;
        }

        return $classes;
    }

    public function container(): ContainerBuilder
    {
        return new InventoryKernel($this->projectDir)->createContainer();
    }

    public function read(string $path): string
    {
        return $this->filesystem->readFile($this->projectDir.'/'.$path);
    }

    public function write(string $path, string $contents): void
    {
        $this->filesystem->dumpFile($this->projectDir.'/'.$path, $contents);
    }

    public function replace(string $path, string $before, string $after): void
    {
        $contents = str_replace($before, $after, $this->read($path), $count);
        if (1 !== $count) {
            throw new \LogicException('Inventory fixture expected exactly one configuration replacement in '.$path.'.');
        }
        $this->write($path, $contents);
    }

    private function initialize(): void
    {
        $this->filesystem->mkdir($this->projectDir, 0700);
        // This fixture exercises module discovery, not the separate CQRS runtime.
        $this->write('config/services.yaml', str_replace("    - { resource: 'services/messaging.yaml' }\n", '', $this->source('config/services.yaml')));
        $this->write('config/packages/framework.yaml', $this->template('framework.yaml'));
        $platformPath = 'src/'.str_replace('\\', '/', substr($this->platformNamespace, 4));
        $this->write($platformPath.'/PlatformService.php', str_replace('App\\Platform\\InventoryFixture', $this->platformNamespace, $this->template('PlatformService.php.fixture')));
        $this->loader->addPsr4($this->platformNamespace.'\\', $this->projectDir.'/'.$platformPath);
        $this->loader->register(true);
    }

    private function source(string $path): string
    {
        return $this->filesystem->readFile(\dirname(__DIR__, 3).'/'.$path);
    }

    private function template(string $path): string
    {
        return $this->filesystem->readFile(__DIR__.'/'.$path);
    }
}
