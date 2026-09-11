<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'task_tracking_boundary_probe', schema: 'public')]
class BoundaryProbe
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(name: 'foreign_id', nullable: true)]
    public ?int $foreignId = null;
}
