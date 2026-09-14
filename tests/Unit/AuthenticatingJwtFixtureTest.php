<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AuthenticatingJwtFixtureTest extends TestCase
{
    public function testSignerRefusesNonTestRuntimeEvenForUnsignedFixtures(): void
    {
        $process = $this->process('dev');
        $process->setInput('{"sub":"bcedd5f0-b683-4ee1-a7d5-35d6ee9c089f","mode":"none"}');
        self::assertSame(1, $process->run());
        self::assertTrue('' === $process->getOutput());
        self::assertSame("JWT fixture generation failed.\n", $process->getErrorOutput());
    }

    public function testMalformedAndOversizedInputHaveFixedDiagnosticsWithoutEchoingPayload(): void
    {
        foreach (['{"sub":', str_repeat('private-fixture-canary-', 1000)] as $input) {
            $process = $this->process('test');
            $process->setInput($input);
            self::assertSame(1, $process->run());
            self::assertTrue('' === $process->getOutput());
            self::assertSame("JWT fixture generation failed.\n", $process->getErrorOutput());
        }
    }

    private function process(string $environment): Process
    {
        return new Process(['php', 'tests/Fixtures/Authenticating/jwt.php'], dirname(__DIR__, 2), ['APP_ENV' => $environment], timeout: 10);
    }
}
