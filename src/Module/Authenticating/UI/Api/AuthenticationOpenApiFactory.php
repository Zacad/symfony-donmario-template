<?php

declare(strict_types=1);

namespace App\Module\Authenticating\UI\Api;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model;
use ApiPlatform\OpenApi\OpenApi;

/** Documents native JSON login without creating an API resource or write operation. */
final readonly class AuthenticationOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $openApi->getPaths()->addPath('/api/login', new Model\PathItem(post: new Model\Operation(
            operationId: 'login',
            tags: ['Authentication'],
            responses: [
                '200' => new Model\Response(description: 'Access token issued. Responses are not cacheable.', content: new \ArrayObject([
                    'application/json' => ['schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['access_token', 'token_type', 'expires_in'],
                        'properties' => [
                            'access_token' => ['type' => 'string'],
                            'token_type' => ['type' => 'string', 'enum' => ['Bearer']],
                            'expires_in' => ['type' => 'integer', 'enum' => [900]],
                        ],
                    ]],
                ])),
                '400' => new Model\Response(description: 'Invalid JSON login request.'),
                '401' => new Model\Response(description: 'Authentication failed.'),
                '411' => new Model\Response(description: 'Framed request body required.'),
                '413' => new Model\Response(description: 'Request body exceeds 16 KiB.'),
                '415' => new Model\Response(description: 'JSON content type required.'),
                '429' => new Model\Response(description: 'Too many authentication attempts.'),
                '503' => new Model\Response(description: 'Authentication temporarily unavailable.'),
            ],
            summary: 'Authenticate with email and password',
            description: 'Returns a 15-minute RS256 access token. Send it only in the Authorization: Bearer header. No refresh token; log in again after expiry. Browser clients keep tokens in memory.',
            requestBody: new Model\RequestBody(content: new \ArrayObject([
                'application/json' => ['schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['email', 'password'],
                    'properties' => [
                        'email' => ['type' => 'string', 'description' => 'Account email; outer ASCII whitespace and case are normalized.'],
                        'password' => ['type' => 'string', 'format' => 'password', 'writeOnly' => true],
                    ],
                ]],
            ]), required: true),
            security: [],
        )));

        return $openApi;
    }
}
