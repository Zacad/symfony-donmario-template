<?php

declare(strict_types=1);

namespace App\Module\Persisting\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'archiving_stolen', schema: 'public')]
class WrongTable
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;
}
