<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'events')]
#[ORM\Index(columns: ['client_id', 'timestamp'], name: 'idx_client_period')]
#[ORM\UniqueConstraint(name: 'uniq_idempotency_key', columns: ['idempotency_key'])]
class EventEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 64)]
    private string $clientId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $endpoint;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $timestamp;

    #[ORM\Column(type: 'string', length: 128)]
    private string $idempotencyKey;

    public function __construct(
        string $id,
        string $clientId,
        string $endpoint,
        \DateTimeImmutable $timestamp,
        string $idempotencyKey,
    ) {
        $this->id = $id;
        $this->clientId = $clientId;
        $this->endpoint = $endpoint;
        $this->timestamp = $timestamp;
        $this->idempotencyKey = $idempotencyKey;
    }

    public function id(): string { return $this->id; }
    public function clientId(): string { return $this->clientId; }
    public function endpoint(): string { return $this->endpoint; }
    public function timestamp(): \DateTimeImmutable { return $this->timestamp; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
}
