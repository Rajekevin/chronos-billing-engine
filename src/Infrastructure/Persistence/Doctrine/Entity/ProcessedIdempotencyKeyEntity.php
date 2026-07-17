<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Dedicated, minimal table backing the idempotency guard. Kept separate
 * from EventEntity so the guard can be checked/written in its own
 * short transaction, and so the same mechanism could later cover other
 * write use cases (invoices, refunds...) without touching events.
 */
#[ORM\Entity]
#[ORM\Table(name: 'processed_idempotency_keys')]
class ProcessedIdempotencyKeyEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 128)]
    private string $idempotencyKey;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $processedAt;

    public function __construct(string $idempotencyKey, \DateTimeImmutable $processedAt)
    {
        $this->idempotencyKey = $idempotencyKey;
        $this->processedAt = $processedAt;
    }

    public function idempotencyKey(): string { return $this->idempotencyKey; }
}
