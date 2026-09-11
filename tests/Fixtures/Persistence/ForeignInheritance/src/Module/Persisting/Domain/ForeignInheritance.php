<?php

declare(strict_types=1);

namespace App\Module\Persisting\Domain;

use App\Module\Archiving\Domain\ArchiveBase;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'persisting_foreign_inheritance', schema: 'public')]
class ForeignInheritance extends ArchiveBase
{
}
