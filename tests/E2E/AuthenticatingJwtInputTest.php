<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Fixtures\Authenticating\JwtHttp;
use PHPUnit\Framework\TestCase;

final class AuthenticatingJwtInputTest extends TestCase
{
    public function testMethodsContentTypesAndMalformedCredentialTypesCannotIssueTokens(): void
    {
        foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
            JwtHttp::denied(JwtHttp::request($method, '/api/login'), 405);
        }
        foreach (['text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data'] as $type) {
            JwtHttp::denied(JwtHttp::request('POST', '/api/login', ['headers' => ['Content-Type' => $type], 'body' => '{"email":"nobody@example.test","password":"invalid-fixture-password"}']), 415);
        }
        foreach (['', '{', 'null', '[]', '"string"', '{}', '{"email":[] ,"password":"password"}', '{"email":"nobody@example.test","password":[]}', '{"email":1,"password":true}', '{"email":"nobody@example.test"}', '{"password":"password"}'] as $body) {
            JwtHttp::denied(JwtHttp::request('POST', '/api/login', ['headers' => ['Content-Type' => 'application/json'], 'body' => $body]), 400);
        }
        foreach ([
            ['email' => str_repeat('a', 255).'@example.test', 'password' => 'invalid-fixture-password'],
            ['email' => 'nobody@example.test', 'password' => str_repeat('a', 4097)],
            ['email' => "nobody\0@example.test", 'password' => 'invalid-fixture-password'],
            ['email' => 'nobody@example.test', 'password' => "invalid-fixture-password\n"],
            ['email' => 'nobody@example.test', 'password' => 'invalid-fixture-password', 'extra' => true],
        ] as $data) {
            JwtHttp::denied(JwtHttp::request('POST', '/api/login', ['json' => $data]), 400);
        }
    }

    public function testApplicationJsonBoundAndCaddyFramingProtectLoginAliases(): void
    {
        foreach ([8192, 16384, 16385] as $size) {
            // Whitespace is legal JSON padding; an otherwise malformed object does
            // not spend a password-check budget. Exactly 16 KiB reaches validation.
            $response = JwtHttp::request('POST', '/api/login', ['headers' => ['Content-Type' => 'application/json'], 'body' => '{}'.str_repeat(' ', $size - 2)]);
            if ($size > 16384) {
                self::assertSame(413, $response->getStatusCode());
            } else {
                JwtHttp::denied($response, 400);
            }
        }
        foreach (['/api/login', '/api/login/', '/api/login//'] as $path) {
            foreach ([false, true] as $chunked) {
                $body = str_repeat('x', 17000);
                $response = JwtHttp::request('POST', $path, ['headers' => ['Content-Type' => 'application/json'], 'body' => $chunked ? (static function () use ($body): \Generator { yield $body; })() : $body]);
                self::assertSame($chunked ? 411 : 413, $response->getStatusCode());
                self::assertFalse(isset($response->getHeaders(false)['set-cookie']));
            }
        }
        foreach (['/index.php/api/login', '/index.php/api/login/', '/index.php/api/me', '/index.php/api/docs.json'] as $path) {
            $response = JwtHttp::request('POST', $path, ['headers' => ['Content-Type' => 'application/json'], 'body' => str_repeat('x', 17000)]);
            self::assertSame(404, $response->getStatusCode());
            self::assertFalse(isset($response->getHeaders(false)['set-cookie']));
        }
    }
}
