<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Domain\ValueObject\Price;

final readonly class InvoiceSummary
{
    public function __construct(
        public string $clientId,
        public int $totalEvents,
        public Price $price,
    ) {
    }
}
