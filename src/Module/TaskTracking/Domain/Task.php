<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Domain;

use App\Module\TaskTracking\Domain\Event\TaskCreatedEvent;
use App\Platform\Event\Recording\RecordsDomainEvents;
use App\Platform\Event\Recording\RecordsDomainEventsTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'task_tracking_task', schema: 'public')]
#[ORM\Index(name: 'task_tracking_task_owner_id_idx', columns: ['owner_account_id', 'id'])]
class Task implements RecordsDomainEvents
{
    use RecordsDomainEventsTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        #[ORM\Column(length: 200)]
        private string $title,
        #[ORM\Column(name: 'owner_account_id', type: UuidType::NAME, nullable: true)]
        private ?Uuid $ownerAccountId = null,
    ) {
        if (!mb_check_encoding($title, 'UTF-8') || str_contains($title, "\0") || '' === trim($title) || mb_strlen($title, 'UTF-8') > 200) {
            throw new \InvalidArgumentException('Task title must contain between 1 and 200 characters.');
        }
        $this->id = Uuid::v7();
        $this->recordDomainEvent(new TaskCreatedEvent($this->id));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function ownerAccountId(): ?Uuid
    {
        return $this->ownerAccountId;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function complete(\DateTimeImmutable $at): bool
    {
        if (null !== $this->completedAt) {
            return false;
        }

        $utc = $at->setTimezone(new \DateTimeZone('UTC'));
        $this->completedAt = $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));

        return true;
    }
}
