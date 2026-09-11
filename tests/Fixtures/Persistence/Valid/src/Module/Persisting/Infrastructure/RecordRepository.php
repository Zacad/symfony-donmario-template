<?php

declare(strict_types=1);

namespace App\Module\Persisting\Infrastructure;

use App\Module\Persisting\Domain\Record;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<Record> */
final class RecordRepository extends EntityRepository
{
}
