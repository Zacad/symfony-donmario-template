<?php

declare(strict_types=1);

namespace App\Module\Persisting\Domain;

use App\Module\Archiving\Domain\ArchiveLabel;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'persisting_foreign_embeddable', schema: 'public')]
class ForeignEmbeddable
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    #[ORM\Embedded(class: ArchiveLabel::class)]
    public ArchiveLabel $label;
}
