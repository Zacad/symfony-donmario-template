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
class Task implements RecordsDomainEvents
{
    use RecordsDomainEventsTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\Column(length: 200)]
        private string $title,
    ) {
        if ('' === trim($title) || mb_strlen($title) > 200) {
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
}
