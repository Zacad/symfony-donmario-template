<?php

declare(strict_types=1);

// An isolated, disposable log generation proves the real secrecy checker catches
// a leak before removal even when the replacement/current generation is clean.
if ('test' !== getenv('APP_ENV')) {
    throw new RuntimeException('Log canary requires isolated test mode.');
}
switch ($argv[1] ?? 'password') {
    case 'password':
        fwrite(STDOUT, 'auth-canary-'.bin2hex(random_bytes(20))."\n");
        break;
    case 'jwt':
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $input = $encode('{"typ":"JWT","alg":"RS256"}').'.'.$encode(json_encode(['sub' => 'log-canary', 'exp' => time() + 60], JSON_THROW_ON_ERROR));
        if (!openssl_sign($input, $signature, file_get_contents('/app/var/jwt/active/private.pem'), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Cannot sign isolated JWT log canary.');
        }
        fwrite(STDOUT, $input.'.'.$encode($signature)."\n");
        break;
    case 'private-key':
        // A real private-key line, without PEM framing, exercises exact canaries.
        $lines = explode("\n", file_get_contents('/app/var/jwt/active/private.pem'));
        fwrite(STDOUT, $lines[1]."\n");
        break;
    default:
        throw new RuntimeException('Unknown authentication log canary.');
}
