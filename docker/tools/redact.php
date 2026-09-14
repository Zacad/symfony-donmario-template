<?php

declare(strict_types=1);

// This deliberately runs without Symfony, even when application startup fails.
$settings = parse_ini_file($argv[1], false, INI_SCANNER_RAW);
if (false === $settings) {
    exit(1);
}
$text = stream_get_contents(STDIN);
if (false === $text) {
    exit(1);
}
foreach (['APP_SECRET', 'APP_DATABASE_PASSWORD', 'POSTGRES_PASSWORD'] as $key) {
    $secret = $settings[$key] ?? null;
    if (!is_string($secret) || '' === $secret) {
        exit(1);
    }
    $text = str_replace($secret, '[REDACTED]', $text);
}
// Disposable authentication tests use generated canaries. Never publish a failed
// assertion's password/hash/cookie/token even when startup or PHP itself fails.
$text = preg_replace([
    '/auth-canary-[a-f0-9]{40}/i',
    '/\$2[aby]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}/',
    '/\$argon2(?:id|i)\$[^\s"\x27<>]+/',
    '/(dm_[a-zA-Z0-9_-]+(?:=|%3D))[a-zA-Z0-9,%_-]+/i',
    '/((_csrf_token|_password)(?:=|%3D))[a-zA-Z0-9%+\/_.~-]+/i',
], '[REDACTED AUTH]', $text);
$text = preg_replace(require __DIR__.'/jwt-log-patterns.php', '[REDACTED JWT]', $text);
if (null === $text) {
    exit(1);
}
fwrite(STDOUT, $text);
