<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Authenticating;

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

final class JwtTokens
{
    public static function expiresAt(#[\SensitiveParameter] string $token): int
    {
        $parts = explode('.', $token);
        Assert::assertTrue(3 === count($parts), 'Expected compact JWT; value withheld.');
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        Assert::assertTrue(is_array($claims) && is_int($claims['exp'] ?? null), 'Expected JWT expiry; payload withheld.');

        return $claims['exp'];
    }

    /** @param array<string, mixed> $claims
     * @param list<string> $remove
     */
    public static function mint(string $id, array $claims = [], array $remove = [], string $mode = 'RS256'): string
    {
        $process = new Process(['php', 'tests/Fixtures/Authenticating/jwt.php'], dirname(__DIR__, 3), timeout: 15);
        $process->setInput(json_encode(['sub' => $id, 'claims' => $claims, 'remove' => $remove, 'mode' => $mode], JSON_THROW_ON_ERROR));
        Assert::assertSame(0, $process->run(), 'Fixture signing failed; output withheld. The isolated test key mount is required.');
        Assert::assertTrue('' === $process->getErrorOutput(), 'Fixture signing emitted diagnostics; output withheld.');
        $token = $process->getOutput();
        Assert::assertTrue(3 === count(explode('.', $token)) && strlen($token) < 8192, 'Fixture did not emit a bounded compact token.');

        return $token;
    }
}
