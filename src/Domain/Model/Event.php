<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\ValueObject\ClientId;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\IdempotencyKey;

final readonly class Event
{
    public function __construct(
        private EventId $id,
        private ClientId $clientId,
        private string $endpoint,
        private \DateTimeImmutable $timestamp,
        private IdempotencyKey $idempotencyKey,
    ) {
    }

    public function id(): EventId
    {
        return $this->id;
    }

    public function clientId(): ClientId
    {
        return $this->clientId;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function timestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }
}
