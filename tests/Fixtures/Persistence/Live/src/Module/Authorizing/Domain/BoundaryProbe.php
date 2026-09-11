<?php

declare(strict_types=1);

namespace App\Module\Authorizing\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'authorizing_boundary_probe', schema: 'public')]
class BoundaryProbe
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;
}
