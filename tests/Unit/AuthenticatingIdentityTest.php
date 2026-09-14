<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Resource\Factory\AttributesResourceMetadataCollectionFactory;
use App\Module\Authenticating\Application\GetAccountIdentity\GetAccountIdentityHandler;
use App\Module\Authenticating\Application\GetAccountIdentity\GetAccountIdentityQuery;
use App\Module\Authenticating\Application\GetAccountIdentity\GetAccountIdentityResult;
use App\Module\Authenticating\Domain\AccountIdentity;
use App\Module\Authenticating\Domain\AccountRepository;
use App\Module\Authenticating\Infrastructure\Framework\Symfony\Security\AccountPrincipal;
use App\Module\Authenticating\Infrastructure\Persistence\DoctrineAccountRepository;
use App\Module\Authenticating\UI\Api\AccountIdentityProvider;
use App\Module\Authenticating\UI\Api\AccountIdentityResource;
use App\Platform\Messaging\QueryBus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Uid\Uuid;

final class AuthenticatingIdentityTest extends TestCase
{
    public function testProviderDispatchesPrincipalUuidAndReturnsFreshQueryIdentityOnly(): void
    {
        $id = Uuid::v7();
        $otherId = Uuid::v7();
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects(self::never())->method('findCredentialsById');
        $accounts->expects(self::never())->method('findCredentialsByEmail');
        $accounts->expects(self::once())->method('findIdentityById')->with($id)->willReturn(new AccountIdentity($id, 'current@example.com'));
        $provider = $this->provider($accounts, $id);

        $resource = $provider->provide(new Get(), ['id' => $otherId->toRfc4122()], ['filters' => ['id' => $otherId->toRfc4122()]]);

        self::assertSame($id->toRfc4122(), $resource->id);
        self::assertSame('current@example.com', $resource->email, 'The query result, not the stale principal email, is projected.');
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
        self::assertSame(['id' => $id->toRfc4122(), 'email' => 'current@example.com'], json_decode($serializer->serialize($resource, 'json'), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['id', 'email'], array_keys(get_object_vars(new GetAccountIdentityResult($id, 'current@example.com'))));
    }

    public function testAccountDeletedAfterAuthenticationIsFixed404(): void
    {
        $id = Uuid::v7();
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects(self::once())->method('findIdentityById')->with($id)->willReturn(null);
        $accounts->expects(self::never())->method('findCredentialsById');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Account not found.');
        $this->provider($accounts, $id)->provide(new Get());
    }

    #[DataProvider('identityRows')]
    public function testApplicationQueryUsesSafeDomainSnapshotAndPreservesMissingIdentity(bool $exists): void
    {
        $id = Uuid::v7();
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects(self::never())->method('findCredentialsById');
        $accounts->expects(self::never())->method('findCredentialsByEmail');
        $accounts->expects(self::once())->method('findIdentityById')->with($id)->willReturn($exists ? new AccountIdentity($id, 'current@example.com') : null);
        $bus = new QueryBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            GetAccountIdentityQuery::class => [new GetAccountIdentityHandler($accounts)],
        ]))]));

        $identity = $bus->ask(new GetAccountIdentityQuery($id));

        if (!$exists) {
            self::assertNull($identity);

            return;
        }
        self::assertInstanceOf(GetAccountIdentityResult::class, $identity);
        self::assertSame(['id' => $id, 'email' => 'current@example.com'], get_object_vars($identity));
    }

    #[DataProvider('missingPrincipals')]
    public function testMissingOrUnrelatedPrincipalNeverDispatchesQuery(bool $unrelatedUser): void
    {
        $tokens = new TokenStorage();
        if ($unrelatedUser) {
            $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('other', null), 'api'));
        }
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Authentication required.');
        new AccountIdentityProvider($tokens, new QueryBus($bus))->provide(new Get());
    }

    /** @return iterable<string, array{bool}> */
    public static function missingPrincipals(): iterable
    {
        yield 'no token' => [false];
        yield 'unrelated user' => [true];
    }

    #[DataProvider('identityRows')]
    public function testRepositoryReadsOnlyIdentityColumnsByBoundUuid(bool $exists): void
    {
        $id = Uuid::v7();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->with(
            'SELECT id, email FROM public.authenticating_account WHERE id = ?',
            [$id->toRfc4122()],
        )->willReturn($exists ? ['id' => $id->toRfc4122(), 'email' => 'current@example.com'] : false);
        $connection->expects(self::never())->method('executeStatement');
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('getConnection')->willReturn($connection);
        $manager->expects(self::never())->method('find');
        $manager->expects(self::never())->method('flush');

        $identity = new DoctrineAccountRepository($manager)->findIdentityById($id);

        if (!$exists) {
            self::assertNull($identity);

            return;
        }
        self::assertInstanceOf(AccountIdentity::class, $identity);
        self::assertTrue($id->equals($identity->id));
        self::assertSame('current@example.com', $identity->email);
        self::assertSame(['id', 'email'], array_keys(get_object_vars($identity)));
    }

    /** @return iterable<string, array{bool}> */
    public static function identityRows(): iterable
    {
        yield 'existing' => [true];
        yield 'missing' => [false];
    }

    public function testQueryFailureDoesNotFallBackToPrincipalIdentity(): void
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects(self::once())->method('findIdentityById')->willThrowException(new \RuntimeException('Identity lookup unavailable.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Identity lookup unavailable.');
        $this->provider($accounts, Uuid::v7())->provide(new Get());
    }

    public function testResourceHasOnlyExplicitSelfGetAndJsonMetadataOutsideApplication(): void
    {
        $metadata = new AttributesResourceMetadataCollectionFactory()->create(AccountIdentityResource::class);
        self::assertCount(1, $metadata);
        $resource = $metadata[0];
        self::assertNotNull($resource);
        self::assertSame(['json' => ['application/json']], $resource->getFormats());
        self::assertTrue($resource->getStateless());
        $operations = $resource->getOperations();
        self::assertNotNull($operations);
        self::assertCount(1, $operations);
        foreach ($operations as $operation) {
            self::assertInstanceOf(Get::class, $operation);
            self::assertSame('/me', $operation->getUriTemplate());
            self::assertSame([], $operation->getUriVariables());
            self::assertSame(AccountIdentityProvider::class, $operation->getProvider());
        }
        foreach ([GetAccountIdentityQuery::class, GetAccountIdentityResult::class] as $class) {
            self::assertSame([], new \ReflectionClass($class)->getAttributes());
            foreach (new \ReflectionClass($class)->getProperties() as $property) {
                self::assertSame([], $property->getAttributes());
            }
        }
    }

    private function provider(AccountRepository $accounts, Uuid $id): AccountIdentityProvider
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new AccountPrincipal($id, 'stale@example.com', 'private-hash-canary'), 'api'));
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            GetAccountIdentityQuery::class => [new GetAccountIdentityHandler($accounts)],
        ]))]);

        return new AccountIdentityProvider($tokens, new QueryBus($bus));
    }
}
