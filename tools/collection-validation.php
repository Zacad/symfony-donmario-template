<?php

declare(strict_types=1);

use App\Tools\Architecture\CollectionValidationKernel;
use App\Tools\Architecture\CollectionValidationMetadata;
use App\Tools\Architecture\SourceRules;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Validator\Validator\ValidatorInterface;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$source = new SourceRules();
$errors = $source->violations($root);
if ([] !== $errors) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}

(new Dotenv())->bootEnv($root.'/.env');
$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
if (!is_string($environment)) {
    fwrite(STDERR, "collection.metadata: invalid kernel environment\n");
    exit(1);
}
$kernel = new CollectionValidationKernel($environment, true);
try {
    $kernel->boot();
    $validator = $kernel->getContainer()->get('collection_validation.validator');
    if (!$validator instanceof ValidatorInterface) {
        throw new LogicException('Native validator unavailable.');
    }
    $errors = (new CollectionValidationMetadata())->violations($source->collections(), $validator);
} catch (Throwable) {
    $errors = ['collection.metadata: kernel/native validator could not be loaded'];
} finally {
    $kernel->shutdown();
}
if ([] !== $errors) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}

fwrite(STDOUT, "Collection validation: source descriptors match loaded native Default-group metadata.\n");
