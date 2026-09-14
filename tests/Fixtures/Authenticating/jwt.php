<?php

declare(strict_types=1);

// Test-app CLI only. Input JSON arrives on stdin; stdout is a private pipe captured
// by the test process, never a published command log. No kernel/service overrides.
// OpenSSL signs deliberately invalid claims which a production issuer cannot emit.
try {
    if ('test' !== getenv('APP_ENV') || 1 !== ($_SERVER['argc'] ?? null)) {
        throw new RuntimeException();
    }
    $input = stream_get_contents(STDIN, 16385);
    if (false === $input || strlen($input) > 16384) {
        throw new RuntimeException();
    }
    $request = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($request) || !is_string($request['sub'] ?? null)) {
        throw new RuntimeException();
    }
    $issuer = 'urn:donmario:'.(getenv('APP_INSTANCE_ID') ?: 'local').':test';
    $now = time();
    $overrides = $request['claims'] ?? [];
    $remove = $request['remove'] ?? [];
    if (!is_array($overrides) || !is_array($remove)) {
        throw new RuntimeException();
    }
    $claims = array_replace(['sub' => $request['sub'], 'iss' => $issuer, 'aud' => $issuer.':api', 'iat' => $now, 'nbf' => $now, 'exp' => $now + 900], $overrides);
    foreach ($remove as $claim) {
        if (!is_string($claim)) {
            throw new RuntimeException();
        }
        unset($claims[$claim]);
    }
    $mode = $request['mode'] ?? 'RS256';
    $algorithm = 'wrong-key' === $mode ? 'RS256' : $mode;
    if (!is_string($algorithm) || !in_array($algorithm, ['RS256', 'RS512', 'HS256', 'none'], true)) {
        throw new RuntimeException();
    }
    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $header = ['typ' => 'JWT', 'alg' => $algorithm];
    $unsigned = $encode(json_encode($header, JSON_THROW_ON_ERROR)).'.'.$encode(json_encode($claims, JSON_THROW_ON_ERROR));
    $signature = '';
    if ('none' !== $mode) {
        if ('HS256' === $mode) {
            $public = @file_get_contents('/app/var/jwt/active/public.pem');
            if (false === $public) {
                throw new RuntimeException();
            }
            $signature = hash_hmac('sha256', $unsigned, $public, true);
        } else {
            if ('wrong-key' === $mode) {
                $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            } else {
                $pem = @file_get_contents('/app/var/jwt/active/private.pem');
                $key = false === $pem ? false : @openssl_pkey_get_private($pem);
            }
            if (false === $key || !openssl_sign($unsigned, $signature, $key, 'RS512' === $mode ? OPENSSL_ALGO_SHA512 : OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException();
            }
        }
    }
    if (!is_string($signature)) {
        throw new RuntimeException();
    }
    fwrite(STDOUT, $unsigned.'.'.$encode($signature));
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "JWT fixture generation failed.\n");
    exit(1);
}
