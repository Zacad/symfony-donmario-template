<?php

declare(strict_types=1);

namespace App\Module\Persisting\Domain;

use App\Module\Persisting\Infrastructure\RecordRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecordRepository::class)]
#[ORM\Table(name: 'persisting_record', schema: 'public')]
class Record extends TaggedRecord
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Tag::class)]
    public ?Tag $primaryTag = null;
}
