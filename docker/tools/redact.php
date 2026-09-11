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
fwrite(STDOUT, $text);
