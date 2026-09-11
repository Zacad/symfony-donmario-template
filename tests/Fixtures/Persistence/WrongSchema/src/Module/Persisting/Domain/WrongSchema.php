<?php

declare(strict_types=1);

namespace App\Module\Persisting\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'persisting_wrong_schema', schema: 'private')]
class WrongSchema
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;
}
