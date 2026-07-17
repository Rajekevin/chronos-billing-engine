<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Model\Invoice;
use App\Domain\ValueObject\Price;

final class BillingCalculator
{
    private const PRICE_PER_EVENT_CENTS = 2; // 0.02 EUR per ingested event
    private const FREE_TIER_THRESHOLD = 1000;

    public function calculate(Invoice $invoice): Price
    {
        $billableEvents = max(0, $invoice->totalEvents() - self::FREE_TIER_THRESHOLD);

        return Price::fromCents($billableEvents * self::PRICE_PER_EVENT_CENTS);
    }

    public function freeTierThreshold(): int
    {
        return self::FREE_TIER_THRESHOLD;
    }

    public function pricePerEventCents(): int
    {
        return self::PRICE_PER_EVENT_CENTS;
    }
}
