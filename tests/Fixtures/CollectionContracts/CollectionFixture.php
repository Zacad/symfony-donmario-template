<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\CollectionContracts;

use App\Tools\Architecture\CollectionContracts;
use App\Tools\Architecture\SourceRules;
use Composer\Autoload\ClassLoader;
use Symfony\Component\Filesystem\Filesystem;

/** Isolated source and optionally autoloadable native-metadata fixtures. */
final readonly class CollectionFixture
{
    public string $root;
    public string $module;
    private Filesystem $filesystem;
    private ClassLoader $loader;

    public function __construct()
    {
        $token = bin2hex(random_bytes(10));
        $this->root = sys_get_temp_dir().'/collection-contracts-'.$token;
        $this->module = 'Collection'.$token.'Checking';
        $this->filesystem = new Filesystem();
        $this->loader = new ClassLoader();
        $this->loader->addPsr4('App\\Module\\'.$this->module.'\\', $this->root.'/src/Module/'.$this->module);
        $this->writeClass('App\\Platform\\Event\\BaseEvent', 'abstract readonly class BaseEvent {}');
        foreach (['Domain', 'Application', 'Infrastructure'] as $category) {
            $this->writeClass('App\\Platform\\Event\\'.$category.'Event', 'abstract readonly class '.$category.'Event extends BaseEvent {}');
        }
        $this->write('LeafInput', 'final readonly class LeafInput { public function __construct(public string $label) {} }');
    }

    public function load(): void
    {
        $this->loader->register(true);
    }

    public function close(): void
    {
        $this->loader->unregister();
        $this->filesystem->remove($this->root);
    }

    /** @return class-string */
    public function name(string $name): string
    {
        /** @var class-string $class Generated fixture class, loaded only by explicit load(). */
        $class = 'App\\Module\\'.$this->module.'\\Application\\Inspect\\'.$name;

        return $class;
    }

    public function write(string $name, string $body): void
    {
        $this->writeClass($this->name($name), $body);
    }

    public function writeClass(string $class, string $body): void
    {
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $this->filesystem->dumpFile($this->root.'/src/'.str_replace('\\', '/', substr($class, 4)).'.php', '<?php namespace '.$namespace.'; '.$body);
    }

    public function mapping(string $yaml): string
    {
        $path = $this->root.'/src/Module/'.$this->module.'/Resources/config/validation.yaml';
        $this->filesystem->dumpFile($path, $yaml);

        return $path;
    }

    public function contracts(): CollectionContracts
    {
        $source = new SourceRules();
        $errors = $source->violations($this->root);
        if ([] !== $errors) {
            throw new \LogicException(implode("\n", $errors));
        }

        return $source->collections();
    }
}
