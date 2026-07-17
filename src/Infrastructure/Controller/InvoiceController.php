<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller;

use App\Application\UseCase\GenerateInvoiceUseCase;
use App\Domain\Exception\InvalidClientIdException;
use App\Domain\Service\BillingCalculator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-side adapter: aggregates a client's ingested events over a period
 * and applies the pricing rule (see BillingCalculator). This is the demo
 * / on-demand equivalent of what a scheduled end-of-month job would do
 * automatically in production.
 */
final class InvoiceController
{
    public function __construct(
        private readonly GenerateInvoiceUseCase $generateInvoiceUseCase,
        private readonly BillingCalculator $billingCalculator,
    ) {
    }

    #[Route('/api/v1/clients/{clientId}/invoice', name: 'clients_invoice', methods: ['GET'])]
    public function __invoke(string $clientId, Request $request): JsonResponse
    {
        try {
            $from = new \DateTimeImmutable((string) $request->query->get('from', '2000-01-01'));
            $to = new \DateTimeImmutable((string) $request->query->get('to', 'now +1 minute'));
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Invalid "from"/"to" date.'], 400);
        }

        try {
            $summary = $this->generateInvoiceUseCase->execute($clientId, $from, $to);
        } catch (InvalidClientIdException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $billableEvents = max(0, $summary->totalEvents - $this->billingCalculator->freeTierThreshold());

        return new JsonResponse([
            'client_id' => $summary->clientId,
            'period' => ['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM)],
            'total_events' => $summary->totalEvents,
            'free_tier_threshold' => $this->billingCalculator->freeTierThreshold(),
            'billable_events' => $billableEvents,
            'price_per_event_cents' => $this->billingCalculator->pricePerEventCents(),
            'amount_cents' => $summary->price->amountInCents(),
        ]);
    }
}
