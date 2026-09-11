<?php

declare(strict_types=1);

namespace App\Module\Persisting\Domain;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'persisting_wrong_join_schema', schema: 'public')]
class WrongJoinSchema
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'persisting_wrong_join_schema', schema: 'private')]
    public Collection $tags;
}
