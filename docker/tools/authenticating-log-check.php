<?php

declare(strict_types=1);

use App\Tests\Fixtures\Authenticating\State;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$consumer = in_array('--consumer', $argv, true);
if (($consumer ? 'dev' : 'test') !== getenv('APP_ENV')) {
    throw new RuntimeException('Log verification requires isolated test mode.');
}
$logs = stream_get_contents(STDIN);
if (false === $logs) {
    throw new RuntimeException('Cannot read authentication log evidence.');
}
$redact = in_array('--redact', $argv, true);
foreach (require __DIR__.'/jwt-log-patterns.php' as $pattern) {
    if ($redact) {
        $logs = preg_replace($pattern, '[REDACTED JWT]', $logs);
    } elseif (preg_match($pattern, $logs)) {
        throw new RuntimeException('Container logs exposed JWT or signing-key material.');
    }
}
// Detect even individual lines of the exercised signing key, without publishing
// key contents or relying on the presence of a PEM header in the log message.
foreach (['/app/var/jwt/active/private.pem', '/app/var/jwt/.outage-private.pem'] as $privatePath) {
    if (!is_file($privatePath)) {
        continue;
    }
    foreach (explode("\n", trim(file_get_contents($privatePath))) as $line) {
        if (strlen($line) < 40 || str_starts_with($line, '-----')) {
            continue;
        }
        if ($redact) {
            $logs = str_replace([$line, rawurlencode($line)], '[REDACTED SIGNING KEY]', $logs);
        } elseif (str_contains($logs, $line) || str_contains($logs, rawurlencode($line))) {
            throw new RuntimeException('Container logs exposed a signing-key canary.');
        }
    }
}
foreach (['/app/var/authenticating-jwt-state.json', '/app/var/authenticating-jwt-throttle.json', '/app/var/consumer-jwt.json'] as $jwtStatePath) {
    if (!is_file($jwtStatePath)) {
        continue;
    }
    $jwtState = json_decode(file_get_contents($jwtStatePath), true, flags: JSON_THROW_ON_ERROR);
    foreach (['password', 'hash', 'token', 'original_token'] as $field) {
        if (!isset($jwtState[$field])) {
            continue;
        }
        $secret = $jwtState[$field];
        if (!is_string($secret) || '' === $secret) {
            throw new RuntimeException('Invalid JWT secrecy verification state.');
        }
        if ($redact) {
            $logs = str_replace([$secret, rawurlencode($secret)], '[REDACTED JWT STATE]', $logs);
        } elseif (str_contains($logs, $secret) || str_contains($logs, rawurlencode($secret))) {
            throw new RuntimeException('Container logs exposed private JWT phase state.');
        }
    }
}
if (!$redact && preg_match('/auth-canary-[a-f0-9]{40}|\$2[aby]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}|\$argon2(?:id|i)\$/i', $logs)) {
    throw new RuntimeException('Container logs exposed authentication credential material.');
}
$statePath = $consumer ? '/app/var/consumer-authenticating.json' : '/app/var/authenticating-state.json';
if (is_file($statePath)) {
    $state = $consumer ? json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR) : State::load();
    foreach ($consumer ? ['password', 'hash', 'session'] : ['password', 'hash', 'session', 'csrf'] as $field) {
        if (!isset($state[$field]) || '' === $state[$field]) {
            throw new RuntimeException('Incomplete authentication log verification state.');
        }
        if ($redact) {
            $logs = str_replace([$state[$field], rawurlencode($state[$field])], '[REDACTED AUTH STATE]', $logs);
        } elseif (str_contains($logs, $state[$field]) || str_contains($logs, rawurlencode($state[$field]))) {
            throw new RuntimeException('Container logs exposed private authentication state.');
        }
    }
}
fwrite(STDOUT, $redact ? $logs : "Verified raw container logs contain no authentication password/hash/session/CSRF/JWT/private-key canaries before redaction.\n");
