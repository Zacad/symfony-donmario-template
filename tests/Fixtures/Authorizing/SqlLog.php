<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Authorizing;

use Psr\Log\AbstractLogger;

/**
 * Bounded, opt-in, memory-only capture. Never retain connection/exception contexts.
 * Fixtures seed credentials on a different, uninstrumented connection. Parameters
 * are retained only for authorization statements, task reads and the safe UUID existence read.
 *
 * @phpstan-type Statement array{sql: string, params: list<bool|int|float|string|null>}
 */
final class SqlLog extends AbstractLogger
{
    /** @var list<Statement> */
    public array $statements = [];
    public int $events = 0;
    private bool $enabled = false;

    public function start(): void
    {
        $this->statements = [];
        $this->events = 0;
        $this->enabled = true;
    }

    public function stop(): void
    {
        $this->enabled = false;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }
        ++$this->events;
        $sql = $context['sql'] ?? null;
        if (!is_string($sql)) {
            return;
        }
        $safe = str_contains($sql, 'authorizing_') || str_starts_with($sql, 'SELECT id FROM public.authenticating_account WHERE id IN (')
            || (str_starts_with($sql, 'SELECT ') && 1 === preg_match('/\bFROM (?:public\.)?task_tracking_task\b/', $sql));
        if (!$safe) {
            // Count unexpected statements too, without retaining their parameters.
            $this->append($sql, []);

            return;
        }
        $parameters = $context['params'] ?? [];
        if (!is_array($parameters)) {
            throw new \LogicException('Unexpected DBAL verification parameters.');
        }
        $scalars = [];
        foreach ($parameters as $value) {
            if (null !== $value && !is_scalar($value)) {
                throw new \LogicException('Unexpected DBAL verification parameter.');
            }
            $scalars[] = $value;
        }
        $this->append($sql, $scalars);
    }

    /** @return list<Statement> */
    public function reads(): array
    {
        return array_values(array_filter($this->statements, static fn (array $row): bool => 1 === preg_match('/\A\s*(?:SELECT|WITH)\b/i', $row['sql']) && !str_contains($row['sql'], 'pg_advisory')));
    }

    /** @return list<Statement> */
    public function writes(): array
    {
        return array_values(array_filter($this->statements, static fn (array $row): bool => 1 === preg_match('/\A\s*(?:INSERT|UPDATE|DELETE)\b/i', $row['sql'])));
    }

    /** @param list<bool|int|float|string|null> $parameters */
    private function append(string $sql, array $parameters): void
    {
        if (count($this->statements) >= 256 || strlen($sql) > 131072 || count($parameters) > 2048) {
            throw new \LogicException('SQL verification capture exceeded its bound.');
        }
        $this->statements[] = ['sql' => $sql, 'params' => $parameters];
    }
}
