<?php

declare(strict_types=1);

namespace App\Module\Persisting\Domain;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'persisting_wrong_join', schema: 'public')]
class WrongJoin
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'archiving_stolen_join', schema: 'public')]
    public Collection $tags;
}
