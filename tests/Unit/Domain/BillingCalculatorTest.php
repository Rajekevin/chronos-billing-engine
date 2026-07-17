<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Model\Event;
use App\Domain\Model\Invoice;
use App\Domain\Service\BillingCalculator;
use App\Domain\ValueObject\ClientId;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\IdempotencyKey;
use PHPUnit\Framework\TestCase;

final class BillingCalculatorTest extends TestCase
{
    private BillingCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new BillingCalculator();
    }

    public function test_it_returns_zero_price_below_free_tier_threshold(): void
    {
        $invoice = $this->buildInvoiceWithEvents(500);

        $price = $this->calculator->calculate($invoice);

        self::assertSame(0, $price->amountInCents());
    }

    public function test_it_bills_only_events_exceeding_free_tier(): void
    {
        $invoice = $this->buildInvoiceWithEvents(1200); // 1000 free + 200 billable

        $price = $this->calculator->calculate($invoice);

        self::assertSame(200 * 2, $price->amountInCents());
    }

    private function buildInvoiceWithEvents(int $count): Invoice
    {
        $clientId = new ClientId('client-00001');
        $invoice = new Invoice(
            $clientId,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        for ($i = 0; $i < $count; $i++) {
            $invoice->addLineItem(new Event(
                EventId::generate(),
                $clientId,
                '/api/v1/resource',
                new \DateTimeImmutable(),
                new IdempotencyKey('key-' . $i),
            ));
        }

        return $invoice;
    }
}
