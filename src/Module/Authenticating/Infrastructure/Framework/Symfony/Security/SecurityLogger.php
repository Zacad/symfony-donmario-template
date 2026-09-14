<?php

declare(strict_types=1);

namespace App\Module\Authenticating\Infrastructure\Framework\Symfony\Security;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/** Native Security diagnostics contain tokens, exceptions and identifiers. */
#[AsDecorator(decorates: 'monolog.logger.security')]
final class SecurityLogger extends AbstractLogger
{
    public function __construct(#[AutowireDecorated] private readonly LoggerInterface $logger)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        try {
            $this->logger->log($level, 'Web security activity.', ['component' => 'authenticating']);
        } catch (\Throwable) {
            // Diagnostic storage cannot change authentication outcomes.
        }
    }
}
