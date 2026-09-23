<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\TaskTracking\Domain\Event\TaskCreatedEvent;
use App\Module\TaskTracking\Domain\InvalidTaskInput;
use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Domain\TaskListCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TaskTrackingDomainTest extends TestCase
{
    public function testOwnershipAndTitleArePreservedAndLegacyConstructionIsOpenOwnerless(): void
    {
        $owner = Uuid::v7();
        $task = new Task('  Résumé  ', $owner);
        self::assertSame('  Résumé  ', $task->title());
        self::assertSame($owner, $task->ownerAccountId());
        self::assertNull($task->completedAt());
        $events = $task->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TaskCreatedEvent::class, $events[0]);
        self::assertSame($task->id(), $events[0]->taskId);
        self::assertSame([], $task->releaseEvents());
        self::assertNull(new Task('Legacy')->ownerAccountId());
    }

    public function testCompletionNormalizesUtcPrecisionAndKeepsFirstInstantWithoutNewEvents(): void
    {
        $task = new Task('Complete once', Uuid::v7());
        $task->releaseEvents();
        self::assertTrue($task->complete(new \DateTimeImmutable('2026-09-16T12:34:56.987654+02:00')));
        $first = $task->completedAt();
        self::assertNotNull($first);
        self::assertSame('2026-09-16T10:34:56.000000+00:00', $first->format('Y-m-d\TH:i:s.uP'));
        self::assertFalse($task->complete(new \DateTimeImmutable('2027-01-01T00:00:00Z')));
        self::assertSame($first, $task->completedAt());
        self::assertSame([], $task->releaseEvents());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTitles(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => [" \t\r\n "];
        yield 'too many characters' => [str_repeat('é', 201)];
        yield 'invalid UTF8' => ["bad\xFF"];
        yield 'embedded NUL' => ["before\0after"];
    }

    #[DataProvider('invalidTitles')]
    public function testInvalidTitlesCannotEnterDomain(string $title): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Task($title);
    }

    public function testTitleLengthCountsCharactersRatherThanBytes(): void
    {
        self::assertSame(str_repeat('é', 200), new Task(str_repeat('é', 200))->title());
    }

    public function testCursorRoundTripsBothTargetsAndUuidValues(): void
    {
        $id = Uuid::v7();
        foreach ([null, Uuid::v7()] as $target) {
            $cursor = TaskListCursor::encode($target, $id);
            self::assertLessThanOrEqual(512, strlen($cursor));
            self::assertTrue($id->equals(TaskListCursor::decode($cursor, $target)));
            self::assertNull(TaskListCursor::decode(null, $target));
        }
    }

    /** @return iterable<string, array{string, ?Uuid}> */
    public static function invalidCursors(): iterable
    {
        $target = Uuid::fromString('01994444-1111-7111-8111-111111111111');
        $id = Uuid::fromString('01994444-2222-7222-8222-222222222222');
        $valid = TaskListCursor::encode($target, $id);
        yield 'empty' => ['', $target];
        yield 'over limit' => [str_repeat('A', 513), $target];
        yield 'padded' => [$valid.'=', $target];
        yield 'newline' => [$valid."\n", $target];
        yield 'bad alphabet' => ['+/==', $target];
        yield 'wrong target' => [$valid, $id];
        yield 'account to operator' => [$valid, null];
        yield 'operator to account' => [TaskListCursor::encode(null, $id), $target];
        foreach (['1.tasks|'.$target.'|'.$id, '2.other|'.$target.'|'.$id, '2.tasks|'.$target.'|not-a-uuid', '2.tasks|'.$target.'|'.$id.'|extra', '2.tasks|'.strtoupper($target->toRfc4122()).'|'.strtoupper($id->toRfc4122())] as $index => $payload) {
            // Include alphabetic UUID digits in the noncanonical UUID case.
            if (4 === $index) {
                $payload = '2.tasks|'.$target.'|01994444-ABCD-7222-8222-222222222222';
            }
            yield 'invalid payload '.$index => [rtrim(strtr(base64_encode($payload), '+/', '-_'), '='), $target];
        }
    }

    #[DataProvider('invalidCursors')]
    public function testCursorRejectsMalformedNoncanonicalOrReboundData(string $cursor, ?Uuid $target): void
    {
        $this->expectException(InvalidTaskInput::class);
        $this->expectExceptionMessage('Invalid task cursor.');
        TaskListCursor::decode($cursor, $target);
    }
}
