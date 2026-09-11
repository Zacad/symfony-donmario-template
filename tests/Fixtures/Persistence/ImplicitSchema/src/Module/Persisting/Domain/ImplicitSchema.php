<?php

declare(strict_types=1);

namespace App\Module\Persisting\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'persisting_implicit_schema')]
class ImplicitSchema
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;
}
