<?php

declare(strict_types=1);

namespace App\Module\Archiving\Infrastructure;

use App\Module\Archiving\Domain\Archive;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<Archive> */
final class ArchiveRepository extends EntityRepository
{
}
