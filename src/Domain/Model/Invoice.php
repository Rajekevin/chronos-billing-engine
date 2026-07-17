<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\ValueObject\ClientId;

final class Invoice
{
    /** @var Event[] */
    private array $lineItems = [];

    public function __construct(
        private readonly ClientId $clientId,
        private readonly \DateTimeImmutable $billingPeriodStart,
        private readonly \DateTimeImmutable $billingPeriodEnd,
    ) {
    }

    public function addLineItem(Event $event): void
    {
        if (!$event->clientId()->equals($this->clientId)) {
            throw new \DomainException('Event does not belong to this invoice client.');
        }

        $this->lineItems[] = $event;
    }

    public function totalEvents(): int
    {
        return count($this->lineItems);
    }

    public function clientId(): ClientId
    {
        return $this->clientId;
    }
}
