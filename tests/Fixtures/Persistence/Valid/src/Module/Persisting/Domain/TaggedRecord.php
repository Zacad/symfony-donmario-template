<?php

declare(strict_types=1);

namespace App\Module\Persisting\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class TaggedRecord
{
    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'persisting_record_tag', schema: 'public')]
    private Collection $tags;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }

    /** @return Collection<int, Tag> */
    public function tags(): Collection
    {
        return $this->tags;
    }
}
