<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use ApiPlatform\Metadata\Resource\Factory\AttributesResourceMetadataCollectionFactory;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model;
use ApiPlatform\OpenApi\OpenApi;
use App\Module\Authenticating\UI\Api\AccountIdentityResource;
use App\Module\Authenticating\UI\Api\AuthenticationOpenApiFactory;
use PHPUnit\Framework\TestCase;

final class AuthenticatingOpenApiTest extends TestCase
{
    public function testNativeLoginContractIsPublicJsonAndPreservesGeneratedIdentityPath(): void
    {
        $paths = new Model\Paths();
        $identityPath = new Model\PathItem(get: new Model\Operation(operationId: 'getAccountIdentity'));
        $paths->addPath('/api/me', $identityPath);
        $generated = new OpenApi(new Model\Info('Fixture API', '1.0'), [], $paths);
        $decorated = $this->createMock(OpenApiFactoryInterface::class);
        $decorated->expects(self::once())->method('__invoke')->with(['base_url' => ''])->willReturn($generated);

        $schema = new AuthenticationOpenApiFactory($decorated)(['base_url' => '']);

        self::assertSame($identityPath, $schema->getPaths()->getPath('/api/me'));
        $loginPath = $schema->getPaths()->getPath('/api/login');
        self::assertNotNull($loginPath);
        self::assertNull($loginPath->getGet());
        $login = $loginPath->getPost();
        self::assertNotNull($login);
        self::assertSame([], $login->getSecurity());
        $body = $login->getRequestBody();
        self::assertNotNull($body);
        self::assertTrue($body->getRequired());
        $content = $body->getContent();
        self::assertNotNull($content);
        self::assertSame(['application/json'], array_keys($content->getArrayCopy()));
        $json = $content['application/json'];
        self::assertIsArray($json);
        self::assertIsArray($json['schema']);
        self::assertSame(['email', 'password'], $json['schema']['required']);
        $responses = $login->getResponses();
        self::assertIsArray($responses);
        $success = $responses['200'];
        self::assertInstanceOf(Model\Response::class, $success);
        $responseContent = $success->getContent();
        self::assertNotNull($responseContent);
        $responseJson = $responseContent['application/json'];
        self::assertIsArray($responseJson);
        self::assertIsArray($responseJson['schema']);
        self::assertSame(['access_token', 'token_type', 'expires_in'], $responseJson['schema']['required']);
        self::assertFalse($responseJson['schema']['additionalProperties']);
        foreach ([401, 411, 413, 429, 503] as $status) {
            self::assertArrayHasKey($status, $responses);
        }
    }

    public function testIdentityDocumentsBearerAndDeletionRaceWithoutCredentials(): void
    {
        $resources = new AttributesResourceMetadataCollectionFactory()->create(AccountIdentityResource::class);
        $resource = $resources[0];
        self::assertNotNull($resource);
        $operations = $resource->getOperations();
        self::assertNotNull($operations);
        foreach ($operations as $operation) {
            $schema = $operation->getOpenapi();
            self::assertInstanceOf(Model\Operation::class, $schema);
            self::assertSame([['bearerAuth' => []]], $schema->getSecurity());
            self::assertNull($schema->getRequestBody());
            $responses = $schema->getResponses();
            self::assertIsArray($responses);
            self::assertArrayHasKey(401, $responses);
            self::assertArrayHasKey(404, $responses);
        }
    }
}
