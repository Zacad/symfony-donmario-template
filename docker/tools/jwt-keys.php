<?php

declare(strict_types=1);

// Standalone, native OpenSSL tooling: never boot Symfony or print key/error data.
umask(0077);
set_error_handler(static function (): never { throw new RuntimeException('JWT key filesystem or cryptographic operation failed.'); });

function jwtWrite(string $path, string $data): void
{
    $stream = fopen($path, 'xb');
    try {
        if (strlen($data) !== fwrite($stream, $data) || !fflush($stream) || !fsync($stream)) {
            throw new RuntimeException('Cannot persist JWT key state.');
        }
    } finally {
        fclose($stream);
    }
}

function jwtPrivateFile(string $path): string
{
    if (is_link($path) || !is_file($path) || 0600 !== (fileperms($path) & 0777) || posix_geteuid() !== fileowner($path)) {
        throw new RuntimeException('JWT key file ownership, permissions or type are invalid.');
    }

    return file_get_contents($path);
}

function jwtSyncDirectory(string $path): void
{
    $stream = fopen($path, 'r');
    try {
        if (!fsync($stream)) {
            throw new RuntimeException('Cannot persist JWT directory state.');
        }
    } finally {
        fclose($stream);
    }
}

function jwtMarker(string $path, string $identity): void
{
    $temporary = $path.'.pending-'.bin2hex(random_bytes(8));
    jwtWrite($temporary, $identity);
    rename($temporary, $path);
    jwtSyncDirectory(dirname($path));
}

function jwtDirectory(string $path): void
{
    if (is_link($path) || !is_dir($path) || 0700 !== (fileperms($path) & 0777) || posix_geteuid() !== fileowner($path)) {
        throw new RuntimeException('JWT key directory ownership, permissions or type are invalid.');
    }
}

function jwtPublic(string $pem): array
{
    $key = openssl_pkey_get_public($pem);
    if (false === $key || false === ($details = openssl_pkey_get_details($key))
        || OPENSSL_KEYTYPE_RSA !== $details['type'] || 3072 !== $details['bits']) {
        throw new RuntimeException('JWT verification key must be RSA 3072.');
    }

    return $details;
}

function jwtValidate(string $root): string
{
    $target = readlink($root.'/current');
    if (!preg_match('#^generations/[a-f0-9]{32}$#D', $target)) {
        throw new RuntimeException('JWT generation pointer is invalid.');
    }
    foreach (['active' => 'current/active', 'previous' => 'current/previous', 'verification.json' => 'current/verification.json'] as $path => $expected) {
        if (!is_link($root.'/'.$path) || $expected !== readlink($root.'/'.$path)) {
            throw new RuntimeException('JWT published paths are invalid.');
        }
    }
    $generation = $root.'/'.$target;
    jwtDirectory($root.'/generations');
    jwtDirectory($generation);
    jwtDirectory($generation.'/active');
    if (['private.pem', 'public.pem'] !== array_values(array_diff(scandir($generation.'/active'), ['.', '..']))) {
        throw new RuntimeException('JWT active generation contains unexpected files.');
    }
    $private = jwtPrivateFile($generation.'/active/private.pem');
    if (!str_starts_with($private, "-----BEGIN PRIVATE KEY-----\n")) {
        throw new RuntimeException('JWT signing key must be unencrypted PKCS8 PEM.');
    }
    $key = openssl_pkey_get_private($private);
    $details = false === $key ? false : openssl_pkey_get_details($key);
    $public = jwtPublic(jwtPrivateFile($generation.'/active/public.pem'));
    if (false === $details || OPENSSL_KEYTYPE_RSA !== $details['type'] || 3072 !== $details['bits'] || $details['key'] !== $public['key']) {
        throw new RuntimeException('JWT signing and verification keys do not match.');
    }
    $previous = file_exists($generation.'/previous') || is_link($generation.'/previous');
    if ($previous) {
        jwtDirectory($generation.'/previous');
        $old = jwtPublic(jwtPrivateFile($generation.'/previous/public.pem'));
        if ($old['key'] === $public['key'] || ['public.pem'] !== array_values(array_diff(scandir($generation.'/previous'), ['.', '..']))) {
            throw new RuntimeException('JWT previous generation must contain one distinct public key only.');
        }
    }
    $manifest = json_decode(jwtPrivateFile($generation.'/verification.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($previous ? ['/app/var/jwt/previous/public.pem'] : []) !== $manifest) {
        throw new RuntimeException('JWT verification manifest does not match published keys.');
    }

    return $generation;
}

function jwtRemoveGeneration(string $directory): void
{
    foreach (scandir($directory) as $entry) {
        if ('.' === $entry || '..' === $entry) {
            continue;
        }
        $path = $directory.'/'.$entry;
        if (is_dir($path) && !is_link($path)) {
            jwtRemoveGeneration($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

function jwtPrune(string $root, string $selected): void
{
    foreach (scandir($root.'/generations') as $entry) {
        if (preg_match('/^[a-f0-9]{32}$/D', $entry) && $root.'/generations/'.$entry !== $selected) {
            jwtRemoveGeneration($root.'/generations/'.$entry);
        }
    }
    jwtSyncDirectory($root.'/generations');
}

try {
    [$script, $operation, $root, $marker] = $argv + [null, null, null, null];
    if (4 !== $argc) {
        throw new RuntimeException('Usage: jwt-keys.php initialize|validate|rotate|rotate-emergency|retire ROOT MARKER');
    }
    if (!in_array($operation, ['initialize', 'validate', 'rotate', 'rotate-emergency', 'retire'], true)
        || !is_dir($root) || is_link($root) || 0700 !== (fileperms($root) & 0777) || posix_geteuid() !== fileowner($root)) {
        throw new RuntimeException('JWT key operation or volume ownership/permissions are invalid.');
    }
    // Kernel flock releases on interruption. Never unlink the stable lock inode.
    $lock = null;
    if ('validate' !== $operation) {
        $lock = fopen($root.'/.lock', 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('JWT key initialization or rotation is already running.');
        }
    }
    $initialized = is_file($root.'/.identity');
    $completed = is_file($marker);
    if (($initialized && !preg_match('/^[a-f0-9]{64}\n$/D', jwtPrivateFile($root.'/.identity')))
        || (!$completed && (file_exists($marker) || is_link($marker)))) {
        throw new RuntimeException('JWT initialization evidence is invalid.');
    }
    if ($completed && (!$initialized || jwtPrivateFile($marker) !== jwtPrivateFile($root.'/.identity'))) {
        throw new RuntimeException('Initialized JWT volume is missing or belongs to another project. Restore the original keys; refusing regeneration.');
    }
    if ($initialized) {
        $active = jwtValidate($root);
        if (!$completed) {
            if ('initialize' !== $operation) {
                throw new RuntimeException('JWT initialization marker is missing. Run setup to validate retained keys.');
            }
            jwtMarker($marker, jwtPrivateFile($root.'/.identity'));
        }
        if ('validate' === $operation || 'initialize' === $operation) {
            if ('initialize' === $operation) {
                // Finish cleanup after a process interruption following publication.
                jwtPrune($root, $active);
            }
            fwrite(STDOUT, "Validated and retained existing JWT RSA3072 keys.\n");
            exit(0);
        }
        if ('rotate' === $operation && is_dir($active.'/previous')) {
            throw new RuntimeException('Retire the previous verification key before another overlap rotation.');
        }
        if ('retire' === $operation && !is_dir($active.'/previous')) {
            fwrite(STDOUT, "No previous JWT verification key to retire.\n");
            exit(0);
        }
    } else {
        if ('initialize' !== $operation || array_diff(scandir($root), ['.', '..', '.lock'])) {
            throw new RuntimeException('JWT volume is uninitialized or partially initialized. Restore or explicitly recover it; refusing regeneration.');
        }
        mkdir($root.'/generations', 0700);
        $active = null;
    }
    $generation = 'generations/'.bin2hex(random_bytes(16));
    $directory = $root.'/'.$generation;
    mkdir($directory, 0700);
    mkdir($directory.'/active', 0700);
    if ('retire' === $operation) {
        jwtWrite($directory.'/active/private.pem', jwtPrivateFile($active.'/active/private.pem'));
        jwtWrite($directory.'/active/public.pem', jwtPrivateFile($active.'/active/public.pem'));
    } else {
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (false === $key || !openssl_pkey_export($key, $private) || false === ($details = openssl_pkey_get_details($key))) {
            throw new RuntimeException('Native OpenSSL JWT generation failed.');
        }
        jwtWrite($directory.'/active/private.pem', $private);
        jwtWrite($directory.'/active/public.pem', $details['key']);
        unset($private, $key, $details);
    }
    $previous = 'rotate' === $operation;
    if ($previous) {
        mkdir($directory.'/previous', 0700);
        jwtWrite($directory.'/previous/public.pem', jwtPrivateFile($active.'/active/public.pem'));
        jwtSyncDirectory($directory.'/previous');
    }
    jwtWrite($directory.'/verification.json', json_encode($previous ? ['/app/var/jwt/previous/public.pem'] : [], JSON_THROW_ON_ERROR)."\n");
    jwtSyncDirectory($directory.'/active');
    jwtSyncDirectory($directory);
    jwtSyncDirectory($root.'/generations');
    if (!$initialized) {
        symlink('current/active', $root.'/active');
        symlink('current/previous', $root.'/previous');
        symlink('current/verification.json', $root.'/verification.json');
    }
    $pointer = $root.'/.switch-'.bin2hex(random_bytes(8));
    symlink($generation, $pointer);
    rename($pointer, $root.'/current');
    jwtSyncDirectory($root);
    jwtValidate($root);
    if (!$initialized) {
        $identity = bin2hex(random_bytes(32))."\n";
        jwtMarker($root.'/.identity', $identity);
        jwtMarker($marker, $identity);
    }
    // Only the selected signing key and (optionally) one old PUBLIC key survive.
    jwtPrune($root, $directory);
    fwrite(STDOUT, "JWT key operation completed; published a matching RSA3072 pair and bounded verification manifest.\n");
} catch (Throwable $error) {
    // OpenSSL/PHP diagnostics can contain sensitive input. Fixed errors only.
    fwrite(STDERR, "JWT key operation refused: incomplete, unavailable, invalid, locked or unexpected key state. Restore retained keys/metadata and inspect the documented recovery procedure.\n");
    exit(1);
}
