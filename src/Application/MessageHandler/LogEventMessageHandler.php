<?php

declare(strict_types=1);

namespace App\Application\MessageHandler;

use App\Application\DTO\LogEventCommand;
use App\Application\Message\LogEventMessage;
use App\Application\UseCase\LogClientEventUseCase;
use App\Domain\Exception\DuplicateEventException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consumes LogEventMessage off the async transport and delegates to the
 * pure Use Case. Kept intentionally thin: all business rules
 * (idempotency, validation) live in LogClientEventUseCase, not here.
 *
 * Duplicate deliveries are swallowed on purpose: under an at-least-once
 * transport (Doctrine/AMQP), redelivery after a worker crash is expected
 * and must NOT be retried forever nor crash the consumer.
 */
#[AsMessageHandler]
final readonly class LogEventMessageHandler
{
    public function __construct(
        private LogClientEventUseCase $useCase,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(LogEventMessage $message): void
    {
        try {
            $this->useCase->execute(new LogEventCommand(
                clientId: $message->clientId,
                endpoint: $message->endpoint,
                idempotencyKey: $message->idempotencyKey,
            ));
        } catch (DuplicateEventException $e) {
            // Idempotent no-op: acknowledge the message without re-throwing,
            // otherwise Messenger would retry it into the failure transport.
            $this->logger->info('chronos.messenger.duplicate_ignored', [
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
