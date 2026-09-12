<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class CommandTransactionMiddleware implements MiddlewareInterface
{
    public function __construct(private ManagerRegistry $doctrine, private InvocationContext $context)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $invoke = function () use ($envelope, $stack): Envelope {
            $handled = $stack->next()->handle($envelope, $stack);
            DispatchResult::from($handled);
            $this->context->assertHealthy();

            return $handled;
        };
        if ($this->context->ownsTransaction()) {
            return $invoke();
        }
        $manager = $this->doctrine->getManager('default');
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('cqrs.manager: the default ORM EntityManager is required.');
        }
        $connection = $manager->getConnection();
        $invalidateTransaction = static function () use ($connection): void {
            if ($connection->isTransactionActive()) {
                // A caught lifecycle rejection during ORM's implicit flush must
                // still prevent commit, without replacing wrapInTransaction().
                $connection->setRollbackOnly();
            }
        };
        if ($connection->isTransactionActive()) {
            throw new \LogicException('cqrs.transaction: an externally opened transaction cannot be adopted.');
        }
        $this->context->startTransaction($invalidateTransaction);
        try {
            return $manager->wrapInTransaction(function () use ($invoke): Envelope {
                $this->context->handlerTime(true);
                try {
                    return $invoke();
                } finally {
                    $this->context->handlerTime(false);
                }
            });
        } catch (\Throwable $failure) {
            // Includes begin failures (outside ORM's try/finally) and rollback
            // failures. Disconnecting also discards DBAL's failed nesting state.
            try {
                try {
                    $manager->close();
                } finally {
                    $connection->close();
                }
            } catch (\Throwable $cleanupFailure) {
                $this->context->markUnusable();
                throw $cleanupFailure;
            }
            throw $failure;
        } finally {
            $this->context->finishTransaction();
        }
    }
}
