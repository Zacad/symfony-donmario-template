<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Infrastructure\Persistence;

use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Domain\TaskRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineTaskRepository implements TaskRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(Task $task): void
    {
        $this->entityManager->persist($task);
    }

    public function find(Uuid $id): ?Task
    {
        return $this->entityManager->find(Task::class, $id);
    }

    public function findForCompletion(Uuid $id): ?Task
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Task completion requires an active transaction.');
        }
        $unitOfWork = $this->entityManager->getUnitOfWork();
        // Removed objects no longer necessarily occur in the identity map.
        foreach ($unitOfWork->getScheduledEntityDeletions() as $removed) {
            if ($removed instanceof Task && $removed->id()->equals($id)) {
                throw new \RuntimeException('Task completion conflict.');
            }
        }

        $managed = $unitOfWork->tryGetById(['id' => $id], Task::class);
        if ($managed instanceof Task && $unitOfWork->isScheduledForInsert($managed)) {
            return $managed;
        }

        // Field-only array hydration converts UUID/date values without touching the identity map.
        /** @var array{id: Uuid, title: string, ownerAccountId: ?Uuid, completedAt: ?\DateTimeImmutable}|null $fresh */
        $fresh = $this->entityManager->createQuery('SELECT t.id AS id, t.title AS title, t.ownerAccountId AS ownerAccountId, t.completedAt AS completedAt FROM '.Task::class.' t WHERE t.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (null === $fresh) {
            if ($managed instanceof Task) {
                throw new \RuntimeException('Task completion conflict.');
            }

            return null;
        }
        if (!$managed instanceof Task) {
            return $this->entityManager->find(Task::class, $id);
        }
        if ($unitOfWork->isUninitializedObject($managed)) {
            $this->entityManager->initializeObject($managed);

            return $managed;
        }

        $original = $unitOfWork->getOriginalEntityData($managed);
        if ($this->sameState($fresh, $original)) {
            return $managed;
        }

        $current = ['id' => $managed->id(), 'title' => $managed->title(), 'ownerAccountId' => $managed->ownerAccountId(), 'completedAt' => $managed->completedAt()];
        if (!$this->sameState($current, $original)) {
            throw new \RuntimeException('Task completion conflict.');
        }

        // The owning row is locked and no pending field changes can be overwritten.
        $this->entityManager->refresh($managed, LockMode::PESSIMISTIC_WRITE);

        return $managed;
    }

    public function findPage(int $limit, ?Uuid $after = null, ?Uuid $ownerAccountId = null): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid task page size.');
        }
        $query = $this->entityManager->createQueryBuilder()->select('t')->from(Task::class, 't')->orderBy('t.id', 'ASC')->setMaxResults($limit + 1);
        if (null !== $ownerAccountId) {
            $query->andWhere('t.ownerAccountId = :ownerAccountId')->setParameter('ownerAccountId', $ownerAccountId, UuidType::NAME);
        }
        if (null !== $after) {
            $query->andWhere('t.id > :after')->setParameter('after', $after, UuidType::NAME);
        }

        /** @var list<Task> $tasks */
        $tasks = $query->getQuery()->getResult();

        return $tasks;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function sameState(array $left, array $right): bool
    {
        foreach (['id', 'title', 'ownerAccountId', 'completedAt'] as $field) {
            if (!array_key_exists($field, $left) || !array_key_exists($field, $right)) {
                return false;
            }
            $a = $left[$field];
            $b = $right[$field];
            if ($a instanceof Uuid && $b instanceof Uuid) {
                if (!$a->equals($b)) {
                    return false;
                }
            } elseif ($a instanceof \DateTimeImmutable && $b instanceof \DateTimeImmutable) {
                if ($a->format('U.u') !== $b->format('U.u')) {
                    return false;
                }
            } elseif ($a !== $b) {
                return false;
            }
        }

        return true;
    }
}
