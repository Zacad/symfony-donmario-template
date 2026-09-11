<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('vendor')
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setCacheFile(__DIR__.'/var/php-cs-fixer.cache')
    ->setRules([
        '@Symfony' => true,
    ])
    ->setFinder($finder)
;
