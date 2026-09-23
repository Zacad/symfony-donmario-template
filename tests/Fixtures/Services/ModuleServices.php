<?php

declare(strict_types=1);

// These synthetic modules exist only in the DI tests, never in the source inventory.

namespace App\Module\DiConsuming\Application {
    use App\Module\DiConsuming\Domain\LocalDependency;
    use App\Module\DiConsuming\Domain\RepositoryPort;
    use App\Module\DiProviding\Infrastructure\Repository;
    use Psr\Container\ContainerInterface;
    use Symfony\Contracts\Service\ServiceSubscriberInterface;

    final class Consumer
    {
        public mixed $configured = null;

        public function __construct(public mixed $dependency = null)
        {
        }

        public function setDependency(mixed $dependency): void
        {
            $this->dependency = $dependency;
        }
    }

    final readonly class RepositoryConsumer
    {
        public function __construct(public RepositoryPort $repository)
        {
        }
    }

    final class ConfiguredRepositoryConsumer
    {
        public RepositoryPort $repository;
        /** @var array<string, string> */
        public array $options = [];

        public function setRepository(RepositoryPort $repository): void
        {
            $this->repository = $repository;
        }

        /** @param array<string, string> $options */
        public function configure(array $options = ['mode' => 'safe'], ?RepositoryPort $repository = null): void
        {
            $this->options = $options;
            $this->repository = $repository ?? throw new \LogicException('Repository was not injected.');
        }
    }

    final readonly class ComplexDefaultsConsumer
    {
        /** @param array<string, string> $options */
        public function __construct(public array $options = ['mode' => 'safe'], public ?RepositoryPort $repository = null)
        {
        }
    }

    final class Factory
    {
        public static function create(mixed $dependency = null): Consumer
        {
            return new Consumer($dependency);
        }

        public static function configure(Consumer $consumer): void
        {
            $consumer->configured = 'configured';
        }
    }

    final readonly class AutowiredConsumer
    {
        public function __construct(public LocalDependency $dependency)
        {
        }
    }

    final readonly class ForeignAutowiredConsumer
    {
        public function __construct(public Repository $dependency)
        {
        }
    }

    final readonly class Subscriber implements ServiceSubscriberInterface
    {
        public function __construct(public ContainerInterface $locator)
        {
        }

        /** @return array<string, class-string> */
        public static function getSubscribedServices(): array
        {
            return ['dependency' => LocalDependency::class];
        }
    }

    final readonly class ForeignSubscriber implements ServiceSubscriberInterface
    {
        public function __construct(public ContainerInterface $locator)
        {
        }

        /** @return array<string, class-string> */
        public static function getSubscribedServices(): array
        {
            return ['repository' => Repository::class];
        }
    }
}

namespace App\Module\DiConsuming\Domain {
    use App\Module\DiConsuming\Infrastructure\Repository;
    use Doctrine\ORM\Mapping as ORM;

    final class LocalDependency
    {
    }

    interface RepositoryPort
    {
        public function label(): string;
    }

    final readonly class RepositoryPolicy
    {
        public function __construct(public RepositoryPort $repository)
        {
        }
    }

    #[ORM\Entity(repositoryClass: Repository::class)]
    final class Record
    {
        #[ORM\Id]
        #[ORM\Column]
        public string $id = 'local';
    }
}

namespace App\Module\DiConsuming\Application\Lookup {
    final class LookupHandler
    {
    }

    final readonly class LookupResult
    {
    }

    final readonly class LookupCommand
    {
    }

    final readonly class LookupQuery
    {
    }
}

namespace App\Module\DiConsuming\Infrastructure {
    use App\Module\DiConsuming\Domain\Record;
    use App\Module\DiConsuming\Domain\RepositoryPort;
    use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
    use Doctrine\Persistence\ManagerRegistry;

    final class RepositoryAdapter implements RepositoryPort
    {
        public function label(): string
        {
            return 'bound-domain-port';
        }
    }

    final readonly class DoctrineConsumer
    {
        public function __construct(public mixed $dependency)
        {
        }
    }

    /** @extends ServiceEntityRepository<Record> */
    final class Repository extends ServiceEntityRepository
    {
        public function __construct(ManagerRegistry $registry)
        {
            parent::__construct($registry, Record::class);
        }
    }
}

namespace App\Module\DiConsuming\Infrastructure\Framework\Symfony\Security {
    use App\Module\DiConsuming\Domain\RepositoryPort;
    use App\Platform\Messaging\QueryBus;
    use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
    use Symfony\Component\Security\Core\Authorization\Voter\Voter;

    /** @extends Voter<string, object> */
    final class DiConsumingVoter extends Voter
    {
        public function __construct(public QueryBus $queries, public RepositoryPort $repository)
        {
        }

        protected function supports(string $attribute, mixed $subject): bool
        {
            return false;
        }

        protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
        {
            return false;
        }
    }
}

namespace App\Module\DiConsuming\Resources\migrations {
    use Doctrine\DBAL\Schema\Schema;
    use Doctrine\Migrations\AbstractMigration;

    final class Version20990101000000 extends AbstractMigration
    {
        public function up(Schema $schema): void
        {
        }
    }
}

namespace App\Module\DiProviding\Domain {
    use App\Module\DiProviding\Infrastructure\Repository;
    use Doctrine\ORM\Mapping as ORM;

    #[ORM\Entity(repositoryClass: Repository::class)]
    final class Record
    {
        #[ORM\Id]
        #[ORM\Column]
        public string $id = 'foreign';
    }
}

namespace App\Module\DiProviding\Application {
    use App\Module\DiConsuming\Domain\LocalDependency;

    final readonly class UnusedConsumer
    {
        public function __construct(public LocalDependency $dependency)
        {
        }
    }
}

namespace App\Module\DiProviding\Application\Lookup {
    final class LookupHandler
    {
    }
}

namespace App\Module\DiProviding\Infrastructure {
    use App\Module\DiConsuming\Application\Consumer;
    use App\Module\DiProviding\Domain\Record;
    use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
    use Doctrine\Persistence\ManagerRegistry;

    final class Factory
    {
        public static function create(mixed $dependency = null): Consumer
        {
            return new Consumer($dependency);
        }

        public static function configure(Consumer $consumer): void
        {
            $consumer->configured = 'foreign';
        }
    }

    /** @extends ServiceEntityRepository<Record> */
    final class Repository extends ServiceEntityRepository
    {
        public function __construct(ManagerRegistry $registry)
        {
            parent::__construct($registry, Record::class);
        }
    }
}

namespace App\Platform\DiFixture {
    final readonly class Wrapper
    {
        public function __construct(public mixed $dependency = null)
        {
        }
    }
}

namespace App\Tests\Fixtures\Services {
    use Psr\Container\ContainerInterface;

    final class ContainerFacade implements ContainerInterface
    {
        public function get(string $id): mixed
        {
            throw new \LogicException('Not used by compilation tests.');
        }

        public function has(string $id): bool
        {
            return false;
        }
    }
}
