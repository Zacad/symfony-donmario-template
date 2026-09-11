<?php

declare(strict_types=1);

// Runs in the trusted bootstrap container, before the application is installed.
[$script, $path, $project, $uid, $gid, $mode] = $argv;
if (!in_array($mode, ['dev', 'test'], true) || is_file($path)) {
    throw new RuntimeException('Settings already exist or invalid mode. Restore settings instead of replacing them.');
}

$values = [
    'PROJECT_ID' => $project,
    'LOCAL_UID' => $uid,
    'LOCAL_GID' => $gid,
    'APP_MODE' => $mode,
    'APP_PORT' => $argv[6] ?? '8080',
    'APP_DATABASE_NAME' => 'test' === $mode ? 'app_test' : 'app',
    'APP_SECRET' => bin2hex(random_bytes(32)),
    'APP_DATABASE_PASSWORD' => bin2hex(random_bytes(32)),
    'POSTGRES_PASSWORD' => bin2hex(random_bytes(32)),
];

umask(0077);
$temporary = $path.'.'.bin2hex(random_bytes(8));
$handle = fopen($temporary, 'x');
if (false === $handle) {
    throw new RuntimeException('Cannot create local settings.');
}
foreach ($values as $key => $value) {
    if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $value)) {
        throw new RuntimeException('Invalid setting.');
    }
    fwrite($handle, $key.'='.$value."\n");
}
fflush($handle);
fsync($handle);
fclose($handle);
if (!rename($temporary, $path)) {
    throw new RuntimeException('Cannot finalize local settings.');
}
