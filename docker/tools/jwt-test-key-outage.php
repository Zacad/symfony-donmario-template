<?php

declare(strict_types=1);

// Narrow destructive fixture, invoked only against a unique disposable test key
// volume by test.sh while the app is stopped. No production/development operation.
umask(0077);
set_error_handler(static function (): never { throw new RuntimeException('Isolated signing outage filesystem failure.'); });
try {
    if ('test' !== getenv('APP_ENV') || !in_array($argv[1] ?? '', ['hide', 'restore'], true)) {
        throw new RuntimeException('Invalid isolated signing outage operation.');
    }
    $root = '/app/var/jwt';
    $lock = fopen($root.'/.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('JWT test key volume is locked.');
    }
    $private = $root.'/active/private.pem';
    $backup = $root.'/.outage-private.pem';
    [$source, $target] = 'hide' === $argv[1] ? [$private, $backup] : [$backup, $private];
    if (!is_file($source) || file_exists($target) || !rename($source, $target)) {
        throw new RuntimeException('Unexpected signing outage fixture state.');
    }
    fwrite(STDOUT, "Isolated signing-key outage state switched.\n");
} catch (Throwable) {
    fwrite(STDERR, "Isolated signing-key outage fixture refused.\n");
    exit(1);
}
