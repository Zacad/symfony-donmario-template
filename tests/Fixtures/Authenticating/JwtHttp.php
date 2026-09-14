<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Authenticating;

use PHPUnit\Framework\Assert;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Real HTTP with deliberately non-diagnostic assertions for credential-bearing data. */
final class JwtHttp
{
    /** @param array<string, mixed> $options */
    public static function request(string $method, string $path, #[\SensitiveParameter] array $options = []): ResponseInterface
    {
        try {
            $response = HttpClient::createForBaseUri(getenv('E2E_BASE_URL') ?: 'http://app:8080', ['max_duration' => 15, 'max_redirects' => 0])->request($method, $path, $options);
            // Buffer before returning: transport exceptions can include the URL
            // used by alternate-token-source negatives. Never publish that URL.
            $response->getContent(false);

            return $response;
        } catch (\Throwable) {
            throw new \RuntimeException('JWT HTTP transport failed; private request details withheld.');
        }
    }

    public static function login(string $email, #[\SensitiveParameter] string $password): ResponseInterface
    {
        return self::request('POST', '/api/login', ['json' => ['email' => $email, 'password' => $password]]);
    }

    public static function me(#[\SensitiveParameter] string $token): ResponseInterface
    {
        return self::request('GET', '/api/me', ['headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']]);
    }

    public static function token(ResponseInterface $response): string
    {
        self::privateResponse($response, 200);
        $data = self::json($response);
        $keys = array_keys($data);
        sort($keys);
        Assert::assertSame(['access_token', 'expires_in', 'token_type'], $keys);
        Assert::assertTrue('Bearer' === ($data['token_type'] ?? null));
        Assert::assertTrue(900 === ($data['expires_in'] ?? null));
        $token = $data['access_token'] ?? null;
        Assert::assertTrue(is_string($token) && 3 === count(explode('.', $token)) && strlen($token) < 8192, 'Login did not return a bounded compact JWT.');

        return $token;
    }

    /** @return array<string, mixed> */
    public static function json(ResponseInterface $response): array
    {
        $data = json_decode($response->getContent(false), true);
        Assert::assertTrue(is_array($data) && !array_is_list($data), 'Expected a JSON object; body withheld.');
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException('Invalid JSON response key.');
            }
        }

        return $data;
    }

    public static function identity(ResponseInterface $response, string $id, string $email): void
    {
        self::privateResponse($response, 200);
        $data = self::json($response);
        $keys = array_keys($data);
        sort($keys);
        Assert::assertSame(['email', 'id'], $keys, 'Identity must expose only Application identity data.');
        Assert::assertTrue($id === ($data['id'] ?? null), 'API returned the wrong UUID.');
        Assert::assertTrue($email === ($data['email'] ?? null), 'API returned stale or wrong email.');
    }

    public static function privateResponse(ResponseInterface $response, int $status): void
    {
        Assert::assertSame($status, $response->getStatusCode());
        $headers = $response->getHeaders(false);
        Assert::assertFalse(isset($headers['set-cookie']), 'Stateless API response created or changed cookies.');
        Assert::assertTrue(str_contains(implode(',', $headers['cache-control'] ?? []), 'no-store'), 'Authentication responses must not be stored.');
        Assert::assertTrue(str_contains(implode(',', $headers['content-type'] ?? []), 'application/json'), 'API response is not JSON.');
    }

    /** @param list<string> $secrets */
    public static function denied(ResponseInterface $response, int $status = 401, #[\SensitiveParameter] array $secrets = []): void
    {
        self::privateResponse($response, $status);
        $data = self::json($response);
        Assert::assertFalse(isset($data['access_token']) || isset($data['id']) || isset($data['email']), 'Failure exposed authentication or identity data.');
        $body = $response->getContent(false);
        foreach ([...$secrets, 'SQLSTATE', 'Stack trace', 'password_hash', 'AccountPrincipal', 'BEGIN PRIVATE KEY', 'BEGIN RSA PRIVATE KEY'] as $secret) {
            Assert::assertFalse('' !== $secret && str_contains($body, $secret), 'API failure exposed private input or implementation details.');
        }
    }
}
