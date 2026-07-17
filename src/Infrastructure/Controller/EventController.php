<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller;

use App\Application\Message\LogEventMessage;
use App\Domain\ValueObject\IdempotencyKey;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Thin HTTP adapter. Deliberately does almost nothing:
 *  1. rate-limit (OWASP A04 / API4:2023 - Unrestricted Resource Consumption)
 *  2. minimal input shaping
 *  3. dispatch to the async Messenger bus and return 202 Accepted
 *
 * Ingestion is high-traffic by design (per the brief), so the write path
 * is decoupled from the HTTP request/response cycle: the client gets an
 * immediate ack, and LogEventMessageHandler processes the event (with
 * full idempotency + validation) off the request thread.
 */
final class EventController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RateLimiterFactory $eventIngestionLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/v1/events', name: 'events_ingest', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $limiter = $this->eventIngestionLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume(1)->isAccepted()) {
            $this->logger->warning('chronos.rate_limit.exceeded', ['ip' => $request->getClientIp()]);

            return new JsonResponse(['error' => 'Too many requests.'], 429);
        }

        $rawBody = $request->getContent();

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Malformed JSON payload.'], 400);
        }

        $clientId = (string) ($payload['client_id'] ?? '');
        $endpoint = (string) ($payload['endpoint'] ?? '');

        // Client-supplied idempotency key (recommended), falling back to a
        // content hash so retried requests without the header are still
        // deduplicated deterministically.
        $idempotencyKey = $request->headers->has('Idempotency-Key')
            ? $request->headers->get('Idempotency-Key')
            : IdempotencyKey::fromPayload($clientId, $endpoint, $rawBody)->value();

        $this->messageBus->dispatch(new LogEventMessage($clientId, $endpoint, (string) $idempotencyKey));

        $this->logger->info('chronos.event.accepted', [
            'client_id' => $clientId,
            'endpoint' => $endpoint,
        ]);

        return new JsonResponse(status: 202); // Accepted: processed asynchronously
    }
}
