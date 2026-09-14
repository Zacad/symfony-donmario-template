<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__, 2).'/vendor/autoload.php';
umask(0077);

try {
    if ('dev' !== getenv('APP_ENV') || !in_array($argv[1] ?? '', ['create', 'read', 'fingerprint'], true)) {
        throw new RuntimeException('Invalid disposable consumer JWT operation.');
    }
    $public = file_get_contents('/app/var/jwt/active/public.pem');
    $fingerprint = hash('sha256', $public);
    if (is_writable('/app/var/jwt/active/private.pem') || 0600 !== (fileperms('/app/var/jwt/active/private.pem') & 0777)) {
        throw new RuntimeException('Consumer signing keys require an owner-only read-only mount.');
    }
    if ('fingerprint' === $argv[1]) {
        fwrite(STDOUT, $fingerprint."\n");
        exit(0);
    }
    $account = json_decode(file_get_contents('/app/var/consumer-authenticating.json'), true, flags: JSON_THROW_ON_ERROR);
    $client = HttpClient::createForBaseUri('http://localhost:8080', ['max_duration' => 15, 'max_redirects' => 0]);
    $login = $client->request('POST', '/api/login', ['json' => ['email' => $account['email'], 'password' => $account['password']]]);
    $response = $login->toArray(false);
    if (200 !== $login->getStatusCode() || 'Bearer' !== ($response['token_type'] ?? null) || 900 !== ($response['expires_in'] ?? null)
        || !is_string($response['access_token'] ?? null) || isset($login->getHeaders(false)['set-cookie'])) {
        throw new RuntimeException('Consumer native JSON login failed.');
    }
    $token = $response['access_token'];
    $identity = $client->request('GET', '/api/me', ['auth_bearer' => $token]);
    $data = $identity->toArray(false);
    if (200 !== $identity->getStatusCode() || $account['id'] !== ($data['id'] ?? null) || $account['email'] !== ($data['email'] ?? null)
        || isset($identity->getHeaders(false)['set-cookie'])) {
        throw new RuntimeException('Consumer bearer identity failed.');
    }
    $file = '/app/var/consumer-jwt.json';
    if ('create' === $argv[1]) {
        if (file_exists($file)) {
            throw new RuntimeException('Consumer JWT state already exists.');
        }
        file_put_contents($file, json_encode(['fingerprint' => $fingerprint, 'token' => $token], JSON_THROW_ON_ERROR));
    }
    $state = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    if ($fingerprint !== $state['fingerprint']) {
        throw new RuntimeException('Consumer signing key changed across setup or test.');
    }
    $parts = explode('.', $state['token']);
    if (3 !== count($parts) || 1 !== openssl_verify($parts[0].'.'.$parts[1], base64_decode(strtr($parts[2], '-_', '+/'), true), $public, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Original consumer token no longer has a valid signature.');
    }
    // Long embedded test runs can outlive a token. Always prove retained signing
    // identity; also replay the original token when it is still within its TTL.
    $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
    if ($claims['exp'] > time() + 5) {
        $original = $client->request('GET', '/api/me', ['auth_bearer' => $state['token']]);
        if (200 !== $original->getStatusCode() || $account['id'] !== ($original->toArray(false)['id'] ?? null)) {
            throw new RuntimeException('Unexpired consumer JWT did not survive setup/recreation.');
        }
    }
    fwrite(STDOUT, "Verified consumer JSON login, stateless bearer identity, read-only key permissions and unchanged signing identity across setup/test/recreation.\n");
} catch (Throwable) {
    fwrite(STDERR, "Disposable consumer JWT verification failed.\n");
    exit(1);
}
