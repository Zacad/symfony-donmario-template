<?php

declare(strict_types=1);

use App\Tests\Fixtures\NativeEvents\NativeEventsFixture;

require \dirname(__DIR__, 3).'/vendor/autoload.php';

$fixture = new NativeEventsFixture();
try {
    $fixture->initialize();
    $violations = $fixture->sourceViolations();
    if ([] !== $violations) {
        throw new RuntimeException(implode("\n", $violations));
    }
    foreach (['sync://', 'doctrine://default'] as $dsn) {
        $compile = $fixture->console(['lint:container'], $dsn);
        if (!$compile->isSuccessful()) {
            throw new RuntimeException($compile->getOutput().$compile->getErrorOutput());
        }
    }
    $route = $fixture->console(['router:match', '/_native/offline', '--method=POST']);
    if (!$route->isSuccessful()) {
        throw new RuntimeException($route->getOutput().$route->getErrorOutput());
    }
    $deptrac = $fixture->process([\dirname(__DIR__, 3).'/vendor/bin/deptrac', 'analyse', '--config-file='.$fixture->projectDir.'/deptrac.php', '--no-progress', '--report-uncovered', '--fail-on-uncovered']);
    $deptrac->run();
    if (!$deptrac->isSuccessful()) {
        throw new RuntimeException($deptrac->getOutput().$deptrac->getErrorOutput());
    }
    echo "Native event fixture: source, both transport container boots and Deptrac passed.\n";
} finally {
    $fixture->remove();
}
