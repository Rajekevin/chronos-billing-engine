<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\DTO\LogEventCommand;
use App\Domain\Exception\DuplicateEventException;
use App\Domain\Model\Event;
use App\Domain\Port\EventRepositoryInterface;
use App\Domain\Port\IdempotencyGuardInterface;
use App\Domain\ValueObject\ClientId;
use App\Domain\ValueObject\EventId;
use App\Domain\ValueObject\IdempotencyKey;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Core use case: ingest one client event.
 *
 * Idempotency is enforced BEFORE persistence: a duplicate delivery
 * (client retry, at-least-once queue redelivery, replayed webhook...)
 * must never be persisted twice, since that would inflate the billable
 * event count computed later by BillingCalculator.
 */
final readonly class LogClientEventUseCase
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private IdempotencyGuardInterface $idempotencyGuard,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function execute(LogEventCommand $command): void
    {
        $logger = $this->logger ?? new NullLogger();
        $idempotencyKey = new IdempotencyKey($command->idempotencyKey);
        $clientId = new ClientId($command->clientId);

        $logContext = [
            'client_id' => $clientId->value(),
            'endpoint' => $command->endpoint,
            'idempotency_key' => $idempotencyKey->value(),
        ];

        if ($this->idempotencyGuard->hasBeenProcessed($idempotencyKey)) {
            $logger->warning('chronos.event.duplicate_rejected', $logContext);

            throw new DuplicateEventException(
                sprintf('Event with idempotency key "%s" was already processed.', $idempotencyKey->value())
            );
        }

        $event = new Event(
            EventId::generate(),
            $clientId,
            $command->endpoint,
            new \DateTimeImmutable(),
            $idempotencyKey,
        );

        $this->eventRepository->save($event);
        $this->idempotencyGuard->markAsProcessed($idempotencyKey);

        $logger->info('chronos.event.ingested', $logContext + ['event_id' => $event->id()->value()]);
    }
}
