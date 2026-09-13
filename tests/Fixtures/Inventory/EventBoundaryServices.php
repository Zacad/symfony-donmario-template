<?php

declare(strict_types=1);

namespace App\Module\EventChecking\Application\Observe {
    final readonly class ObservedEvent extends \App\Platform\Event\ApplicationEvent
    {
        public function __construct(public string $id)
        {
        }
    }

    final readonly class WrongCategoryEvent extends \App\Platform\Event\DomainEvent
    {
    }

    final readonly class UnrelatedEvent
    {
    }
}

namespace App\Module\EventChecking\Domain\Event {
    final readonly class ChangedEvent extends \App\Platform\Event\DomainEvent
    {
        public function __construct(public string $id)
        {
        }
    }
}

namespace App\Module\EventChecking\Infrastructure\Event {
    final readonly class ReceivedEvent extends \App\Platform\Event\InfrastructureEvent
    {
        public function __construct(public string $id)
        {
        }
    }
}

namespace App\Module\EventChecking\Domain {
    interface RepositoryPort
    {
    }
}

namespace App\Module\EventChecking\Infrastructure {
    final class Adapter
    {
        public function __construct(public mixed $dependency = null)
        {
        }
    }

    final class Repository implements \App\Module\EventChecking\Domain\RepositoryPort
    {
    }
}

namespace App\Module\EventChecking\Infrastructure\EventListener {
    final class ObservedListener
    {
        public mixed $dependency = null;

        public function __construct(mixed $dependency = null)
        {
            $this->dependency = $dependency;
        }

        public function setRepository(\App\Module\EventChecking\Domain\RepositoryPort $repository): void
        {
            $this->dependency = $repository;
        }
    }
}

namespace App\Module\EventChecking\Application {
    final readonly class Misplaced extends \App\Platform\Event\DomainEvent
    {
    }
}

namespace App\Module\EventChecking\UI\Http {
    final class Controller
    {
        public function __construct(public mixed $dependency = null)
        {
        }
    }
}
