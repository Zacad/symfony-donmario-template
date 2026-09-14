<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Authenticating;

use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;

/** Real HTTP only; no kernel browser, test route or shared session volume. */
final class Browser
{
    public static function create(?string $session = null, ?string $base = null): HttpBrowser
    {
        $base ??= getenv('E2E_BASE_URL') ?: 'http://app:8080';
        $host = parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT);
        if (!is_string($host)) {
            throw new \RuntimeException('Invalid authentication test base URL.');
        }
        $browser = new HttpBrowser(HttpClient::createForBaseUri($base, ['max_duration' => 15]));
        // BrowserKit resolves relative URLs before passing them to HttpClient.
        $browser->setServerParameter('HTTP_HOST', $host.(is_int($port) ? ':'.$port : ''));
        $browser->setServerParameter('HTTPS', 'https' === parse_url($base, PHP_URL_SCHEME) ? 'on' : '');
        $browser->followRedirects(false);
        if (null !== $session) {
            $browser->getCookieJar()->set(new Cookie(self::cookieName(), $session, null, '/', $host, false, true, false, 'lax'));
        }

        return $browser;
    }

    public static function cookieName(): string
    {
        return 'dm_'.(getenv('APP_INSTANCE_ID') ?: 'local').'_'.(getenv('APP_ENV') ?: 'test');
    }

    public static function session(HttpBrowser $browser): string
    {
        foreach ($browser->getCookieJar()->all() as $cookie) {
            if (self::cookieName() === $cookie->getName()) {
                return $cookie->getValue();
            }
        }
        throw new \RuntimeException('Expected native namespaced session cookie.');
    }

    public static function token(HttpBrowser $browser, string $path = '/login'): string
    {
        $crawler = $browser->request('GET', $path);
        if (200 !== $browser->getResponse()->getStatusCode()) {
            throw new \RuntimeException('Cannot obtain authentication form.');
        }
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        if (null === $token || '' === $token) {
            throw new \RuntimeException('Missing session CSRF token.');
        }

        return $token;
    }

    public static function login(HttpBrowser $browser, string $email, #[\SensitiveParameter] string $password): void
    {
        $token = self::token($browser);
        $browser->request('POST', '/login', ['_username' => $email, '_password' => $password, '_csrf_token' => $token]);
    }

    public static function redirectedTo(HttpBrowser $browser, string $path): bool
    {
        $response = $browser->getResponse();
        $location = self::header($browser, 'Location');

        return 302 === $response->getStatusCode() && '' !== $location
            && $path === parse_url($location, PHP_URL_PATH)
            && (null === parse_url($location, PHP_URL_HOST) || parse_url($browser->getRequest()->getUri(), PHP_URL_HOST) === parse_url($location, PHP_URL_HOST));
    }

    public static function header(HttpBrowser $browser, string $name): string
    {
        $value = $browser->getResponse()->getHeader($name);
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $part) {
                if (!is_string($part)) {
                    throw new \RuntimeException('Invalid HTTP response header.');
                }
                $parts[] = $part;
            }

            return implode('; ', $parts);
        }

        return $value ?? '';
    }

    public static function secret(): string
    {
        return 'auth-canary-'.bin2hex(random_bytes(20));
    }
}
