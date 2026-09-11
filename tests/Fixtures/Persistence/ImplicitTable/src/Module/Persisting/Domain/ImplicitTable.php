<?php

declare(strict_types=1);

namespace App\Module\Persisting\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ImplicitTable
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;
}
