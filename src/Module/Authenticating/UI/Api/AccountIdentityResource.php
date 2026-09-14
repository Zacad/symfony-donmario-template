<?php

declare(strict_types=1);

namespace App\Module\Authenticating\UI\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;

#[ApiResource(
    shortName: 'AccountIdentity',
    operations: [new Get(
        uriTemplate: '/me',
        uriVariables: [],
        openapi: new Operation(
            operationId: 'getAccountIdentity',
            tags: ['Authentication'],
            responses: [
                '401' => new Response(description: 'Authentication required.'),
                '404' => new Response(description: 'Account not found: deleted between authentication and identity lookup.'),
            ],
            summary: 'Read the authenticated account identity',
            security: [['bearerAuth' => []]],
        ),
        provider: AccountIdentityProvider::class,
    )],
    formats: ['json' => ['application/json']],
    stateless: true,
)]
final readonly class AccountIdentityResource
{
    public function __construct(
        #[ApiProperty(identifier: true, writable: false, openapiContext: ['type' => 'string', 'format' => 'uuid'])]
        public string $id,
        #[ApiProperty(writable: false, openapiContext: ['type' => 'string', 'format' => 'email'])]
        public string $email,
    ) {
    }
}
