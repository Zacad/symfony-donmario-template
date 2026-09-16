<?php

declare(strict_types=1);

namespace App\Module\Authorizing\UI\Console;

use App\Module\Authorizing\Application\ListAccountAssignments\AssignmentCursorInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Uid\Uuid;

final class AuthorizationConsoleInput
{
    public function configureScope(Command $command): void
    {
        $command
            ->addOption('global', null, InputOption::VALUE_NONE, 'Use global scope (exclusive with resource options).')
            ->addOption('resource-type', null, InputOption::VALUE_REQUIRED, 'Exact resource type; requires --resource-id.')
            ->addOption('resource-id', null, InputOption::VALUE_REQUIRED, 'Resource UUID; requires --resource-type.');
    }

    /** @return array{string, ?string, ?Uuid} */
    public function scope(InputInterface $input): array
    {
        $global = $input->getOption('global');
        $type = $input->getOption('resource-type');
        $id = $input->getOption('resource-id');
        if (true === $global && null === $type && null === $id) {
            return ['global', null, null];
        }
        if (false === $global && null !== $type && null !== $id) {
            return ['resource', $this->text($type), $this->uuid($id)];
        }

        throw new \DomainException('Invalid authorization input.');
    }

    public function text(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 64) {
            throw new \DomainException('Invalid authorization input.');
        }

        return $value;
    }

    public function uuid(mixed $value): Uuid
    {
        $value = $this->text($value);
        if (!Uuid::isValid($value)) {
            throw new \DomainException('Invalid authorization input.');
        }

        return Uuid::fromString($value);
    }

    /** @return list<\stdClass> */
    public function batch(InputInterface $input): array
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $stream ??= STDIN;
        $json = stream_get_contents($stream, 65537);
        if (false === $json) {
            throw new \RuntimeException('Authorization input unavailable.');
        }
        if (strlen($json) > 65536) {
            throw new \DomainException('Invalid authorization input.');
        }
        $items = $this->decode($json);
        // Decode objects as objects: {"0": ...} must never become a JSON list.
        if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 100) {
            throw new \DomainException('Invalid authorization input.');
        }
        $objects = [];
        foreach ($items as $item) {
            if (!$item instanceof \stdClass) {
                throw new \DomainException('Invalid authorization input.');
            }
            $objects[] = $item;
        }

        return $objects;
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     *
     * @return array<string, mixed>
     */
    public function fields(\stdClass $item, array $required, array $optional = []): array
    {
        $fields = get_object_vars($item);
        if ([] !== array_diff($required, array_keys($fields)) || [] !== array_diff(array_keys($fields), [...$required, ...$optional])) {
            throw new \DomainException('Invalid authorization input.');
        }

        /** @var array<string, mixed> $accepted */
        $accepted = $fields;

        return $accepted;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{string, ?string, ?Uuid}
     */
    public function batchScope(array $fields): array
    {
        $scope = $this->text($fields['scope']);
        // Optional fields may be absent, but supplied values must be strings.
        $type = array_key_exists('resourceType', $fields) ? $this->text($fields['resourceType']) : null;
        $id = array_key_exists('resourceId', $fields) ? $this->uuid($fields['resourceId']) : null;
        if ('global' === $scope && null === $type && null === $id) {
            return [$scope, null, null];
        }
        if ('resource' === $scope && null !== $type && null !== $id) {
            return [$scope, $type, $id];
        }

        throw new \DomainException('Invalid authorization input.');
    }

    public function limit(mixed $value): int
    {
        if (!is_string($value) || 1 !== preg_match('/\A(?:[1-9][0-9]?|100)\z/', $value)) {
            throw new \DomainException('Invalid authorization input.');
        }

        return (int) $value;
    }

    public function cursor(mixed $value): ?AssignmentCursorInput
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || strlen($value) > 512 || 1 !== preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) {
            throw new \DomainException('Invalid authorization input.');
        }
        $json = base64_decode(strtr($value, '-_', '+/'), true);
        if (false === $json || rtrim(strtr(base64_encode($json), '+/', '-_'), '=') !== $value) {
            throw new \DomainException('Invalid authorization input.');
        }
        $decoded = $this->decode($json);
        if (!$decoded instanceof \stdClass) {
            throw new \DomainException('Invalid authorization input.');
        }
        $fields = $this->fields($decoded, ['accountId', 'source', 'assignmentId']);
        if (!is_int($fields['source']) || $fields['source'] < 0 || $fields['source'] > 3) {
            throw new \DomainException('Invalid authorization input.');
        }

        return new AssignmentCursorInput($this->uuid($fields['accountId']), $fields['source'], $this->uuid($fields['assignmentId']));
    }

    private function decode(string $json): mixed
    {
        try {
            return json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \DomainException('Invalid authorization input.');
        }
    }
}
