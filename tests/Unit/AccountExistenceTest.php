<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceHandler;
use App\Module\Authenticating\Application\CheckAccountExistence\CheckAccountExistenceQuery;
use App\Module\Authenticating\Domain\AccountRepository;
use App\Module\Authenticating\Infrastructure\Persistence\DoctrineAccountRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AccountExistenceTest extends TestCase
{
    public function testOneBatchReadPreservesOrderDuplicatesAndMissingAccountsWithoutCredentials(): void
    {
        $first = Uuid::v7();
        $missing = Uuid::v7();
        $last = Uuid::v7();
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects(self::once())->method('existingIds')->with([$first, $missing, $last])->willReturn([$last, $first]);
        $accounts->expects(self::never())->method('findCredentialsById');
        $accounts->expects(self::never())->method('findCredentialsByEmail');
        $accounts->expects(self::never())->method('findIdentityById');
        $input = [$first, $missing, $last, Uuid::fromString($first->toRfc4122()), $missing];

        $result = new CheckAccountExistenceHandler($accounts)(new CheckAccountExistenceQuery($input));

        self::assertCount(5, $result->accounts);
        foreach ($result->accounts as $index => $account) {
            self::assertSame(['accountId' => $input[$index], 'exists' => [true, false, true, true, false][$index]], get_object_vars($account));
        }
    }

    public function testRepositoryUsesOneParameterizedUuidOnlyReadForOneHundredAccounts(): void
    {
        $ids = array_map(static fn (): Uuid => Uuid::v7(), range(1, 100));
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchFirstColumn')->with(
            'SELECT id FROM public.authenticating_account WHERE id IN (?)',
            [array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids)],
            [ArrayParameterType::STRING],
        )->willReturn([$ids[99]->toRfc4122(), $ids[0]->toRfc4122()]);
        $connection->expects(self::never())->method('executeStatement');
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('getConnection')->willReturn($connection);
        $manager->expects(self::never())->method('flush');

        $existing = new DoctrineAccountRepository($manager)->existingIds($ids);

        self::assertCount(2, $existing);
        self::assertTrue($ids[99]->equals($existing[0]));
        self::assertTrue($ids[0]->equals($existing[1]));
    }

    public function testExistenceFailurePropagatesWithoutManufacturingMissingDecisions(): void
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->expects(self::once())->method('existingIds')->willThrowException(new \RuntimeException('Existence unavailable.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Existence unavailable.');
        new CheckAccountExistenceHandler($accounts)(new CheckAccountExistenceQuery([Uuid::v7()]));
    }
}
