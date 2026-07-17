<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Repository;

use App\Domain\Port\IdempotencyGuardInterface;
use App\Domain\ValueObject\IdempotencyKey;
use App\Infrastructure\Persistence\Doctrine\Entity\ProcessedIdempotencyKeyEntity;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Backed by a UNIQUE constraint on `idempotency_key` (see
 * ProcessedIdempotencyKeyEntity). This makes markAsProcessed() atomic at
 * the database level: under concurrent requests carrying the same key,
 * exactly one INSERT succeeds and the loser gets a constraint violation,
 * which we translate back into "already processed" rather than a 500.
 *
 * This is deliberately stronger than a plain SELECT-then-INSERT check
 * (which would be a race condition under high concurrent traffic -
 * relevant given Chronos is a high-throughput ingestion API).
 */
final readonly class DoctrineIdempotencyGuard implements IdempotencyGuardInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function hasBeenProcessed(IdempotencyKey $key): bool
    {
        return null !== $this->entityManager
            ->getRepository(ProcessedIdempotencyKeyEntity::class)
            ->find($key->value());
    }

    public function markAsProcessed(IdempotencyKey $key): void
    {
        try {
            $this->entityManager->persist(
                new ProcessedIdempotencyKeyEntity($key->value(), new \DateTimeImmutable())
            );
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Lost a race against a concurrent identical request - the key
            // is now guaranteed to be marked processed by the winner.
            $this->entityManager->clear(ProcessedIdempotencyKeyEntity::class);
        }
    }
}
