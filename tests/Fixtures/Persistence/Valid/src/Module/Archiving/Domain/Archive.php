<?php

declare(strict_types=1);

namespace App\Module\Archiving\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'archiving_archive', schema: 'public')]
class Archive extends ArchiveBase
{
    #[ORM\Embedded(class: ArchiveLabel::class)]
    public ArchiveLabel $label;
}
