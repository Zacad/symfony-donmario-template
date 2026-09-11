<?php

declare(strict_types=1);

namespace App\Module\Archiving\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class ArchiveBase
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;
}
