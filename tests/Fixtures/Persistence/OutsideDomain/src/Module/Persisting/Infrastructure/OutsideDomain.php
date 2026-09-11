<?php

declare(strict_types=1);

namespace App\Module\Persisting\Infrastructure;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'persisting_outside_domain', schema: 'public')]
class OutsideDomain
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;
}
