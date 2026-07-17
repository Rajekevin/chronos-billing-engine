<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Mapper;

use App\Domain\Model\Event;
use App\Domain\ValueObject\ClientId;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\IdempotencyKey;
use App\Infrastructure\Persistence\Doctrine\Entity\EventEntity;

final class EventMapper
{
    public function toEntity(Event $event): EventEntity
    {
        return new EventEntity(
            $event->id()->value(),
            $event->clientId()->value(),
            $event->endpoint(),
            $event->timestamp(),
            $event->idempotencyKey()->value(),
        );
    }

    public function toDomain(EventEntity $entity): Event
    {
        return new Event(
            new EventId($entity->id()),
            new ClientId($entity->clientId()),
            $entity->endpoint(),
            $entity->timestamp(),
            new IdempotencyKey($entity->idempotencyKey()),
        );
    }
}
