<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\UI\Http;

use App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand;
use App\Module\TaskTracking\Application\GetTask\GetTaskQuery;
use App\Module\TaskTracking\Application\GetTask\GetTaskResult;
use App\Platform\Messaging\CommandBus;
use App\Platform\Messaging\QueryBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

#[AsController]
final readonly class TaskController
{
    public function __construct(private CommandBus $commands, private QueryBus $queries, private UrlGeneratorInterface $urls, private LoggerInterface $logger)
    {
    }

    #[Route('/_demo/tasks', name: 'demo_task_create', methods: ['POST'], stateless: true, env: ['dev', 'test'])]
    public function create(Request $request): JsonResponse
    {
        if ('application/json' !== strtolower(trim(explode(';', $request->headers->get('Content-Type') ?? '')[0]))) {
            return $this->response(['error' => 'unsupported_content_type'], 415);
        }
        $stream = $request->getContent(true);
        if (!is_resource($stream)) {
            return $this->response(['error' => 'invalid_payload'], 400);
        }
        $body = stream_get_contents($stream, 4097);
        if (false === $body) {
            return $this->response(['error' => 'invalid_payload'], 400);
        }
        if (strlen($body) > 4096) {
            return $this->response(['error' => 'body_too_large'], 413);
        }
        try {
            $payload = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->response(['error' => 'invalid_payload'], 400);
        }
        if (!$payload instanceof \stdClass || ['title'] !== array_keys(get_object_vars($payload)) || !is_string($payload->title)) {
            return $this->response(['error' => 'invalid_payload'], 400);
        }
        try {
            $id = $this->commands->dispatch(new CreateTaskCommand($payload->title));
            if (!$id instanceof Uuid) {
                throw new \LogicException('Unexpected create result.');
            }

            return $this->response(['id' => $id->toRfc4122()], 201, ['Location' => $this->urls->generate('demo_task_show', ['id' => $id->toRfc4122()])]);
        } catch (ValidationFailedException $failure) {
            return $this->validation($failure);
        } catch (\Throwable $failure) {
            return $this->failed('create_task', $failure);
        }
    }

    #[Route('/_demo/tasks/{id}', name: 'demo_task_show', methods: ['GET'], stateless: true, env: ['dev', 'test'])]
    public function show(string $id): JsonResponse
    {
        try {
            $result = $this->queries->ask(new GetTaskQuery($id));
            if (null === $result) {
                return $this->response(['error' => 'task_not_found'], 404);
            }
            if (!$result instanceof GetTaskResult) {
                throw new \LogicException('Unexpected lookup result.');
            }

            return $this->response(['id' => $result->id->toRfc4122(), 'title' => $result->title]);
        } catch (ValidationFailedException $failure) {
            return $this->validation($failure);
        } catch (\Throwable $failure) {
            return $this->failed('get_task', $failure);
        }
    }

    private function validation(ValidationFailedException $failure): JsonResponse
    {
        $violations = [];
        foreach ($failure->getViolations() as $violation) {
            $violations[] = ['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
        }

        return $this->response(['error' => 'validation_failed', 'violations' => $violations], 422);
    }

    private function failed(string $operation, \Throwable $failure): JsonResponse
    {
        $this->logger->error('Task operation failed.', ['operation' => $operation, 'failure_type' => $failure::class]);

        return $this->response(['error' => 'operation_failed'], 500);
    }

    /** @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function response(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store', ...$headers]);
    }
}
