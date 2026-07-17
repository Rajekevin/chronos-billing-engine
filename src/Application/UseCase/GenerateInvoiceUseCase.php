<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\DTO\InvoiceSummary;
use App\Domain\Model\Invoice;
use App\Domain\Port\EventRepositoryInterface;
use App\Domain\Port\InvoiceRepositoryInterface;
use App\Domain\Service\BillingCalculator;
use App\Domain\ValueObject\ClientId;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class GenerateInvoiceUseCase
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private InvoiceRepositoryInterface $invoiceRepository,
        private BillingCalculator $billingCalculator,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function execute(string $rawClientId, \DateTimeImmutable $from, \DateTimeImmutable $to): InvoiceSummary
    {
        $logger = $this->logger ?? new NullLogger();
        $clientId = new ClientId($rawClientId);

        $invoice = new Invoice($clientId, $from, $to);

        foreach ($this->eventRepository->findByClientAndPeriod($clientId, $from, $to) as $event) {
            $invoice->addLineItem($event);
        }

        $price = $this->billingCalculator->calculate($invoice);

        $this->invoiceRepository->save($invoice);

        $logger->info('chronos.invoice.generated', [
            'client_id' => $clientId->value(),
            'total_events' => $invoice->totalEvents(),
            'amount_cents' => $price->amountInCents(),
        ]);

        return new InvoiceSummary($clientId->value(), $invoice->totalEvents(), $price);
    }
}
