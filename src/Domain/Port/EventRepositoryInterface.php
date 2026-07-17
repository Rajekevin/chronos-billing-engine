<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Model\Event;
use App\Domain\ValueObject\ClientId;

interface EventRepositoryInterface
{
    public function save(Event $event): void;

    /** @return Event[] */
    public function findByClientAndPeriod(
        ClientId $clientId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array;
}
