<?php

declare(strict_types=1);

namespace App\Module\Archiving\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class ArchiveLabel
{
    #[ORM\Column]
    public string $value;
}
