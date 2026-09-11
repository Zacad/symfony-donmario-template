<?php

declare(strict_types=1);

use App\Tools\Architecture\SourceRules;

require dirname(__DIR__).'/vendor/autoload.php';

$violations = (new SourceRules())->violations(dirname(__DIR__));
if ([] !== $violations) {
    fwrite(STDERR, implode("\n", $violations)."\n");
    exit(1);
}

fwrite(STDOUT, "Source architecture: all first-party declarations and public contracts covered.\n");
