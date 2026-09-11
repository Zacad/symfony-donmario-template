<?php

declare(strict_types=1);

namespace App\Module\Persisting\Domain;

use App\Module\Archiving\Infrastructure\ArchiveRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArchiveRepository::class)]
#[ORM\Table(name: 'persisting_wrong_repository', schema: 'public')]
class WrongRepository
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;
}
