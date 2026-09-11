<?php

declare(strict_types=1);

namespace App\Module\Persisting\Domain;

use App\Module\Archiving\Domain\Archive;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'persisting_foreign_association', schema: 'public')]
class ForeignAssociation
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Archive::class)]
    public ?Archive $archive = null;
}
