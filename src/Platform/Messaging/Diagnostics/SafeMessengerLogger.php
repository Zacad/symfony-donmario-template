<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Diagnostics;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/** The native messenger channel must never interpolate payloads or exceptions. */
final class SafeMessengerLogger extends AbstractLogger
{
    public function __construct(private readonly LoggerInterface $inner)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $metadata = [];
        foreach (['message_id', 'retryCount', 'delay', 'count'] as $key) {
            $value = $context[$key] ?? null;
            if (is_int($value) || (is_string($value) && strlen($value) <= 20 && ctype_digit($value))) {
                $metadata[$key] = $value;
            }
        }
        if (is_string($context['class'] ?? null) && class_exists($context['class'], false)) {
            $metadata['class'] = $context['class'];
        }
        try {
            $this->inner->log($level, 'messenger.activity', $metadata);
        } catch (\Throwable) {
            // Diagnostics cannot change native ACK/retry or producer outcomes.
        }
    }
}
