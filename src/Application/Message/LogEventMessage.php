<?php

declare(strict_types=1);

namespace App\Application\Message;

/**
 * Plain, serializable message dispatched onto the Symfony Messenger bus.
 *
 * Deliberately framework-agnostic in shape (scalars only) so it can be
 * serialized to the AMQP/Doctrine transport without coupling the Domain
 * to Messenger. This is the asynchronous counterpart of LogEventCommand.
 */
final class LogEventMessage
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $endpoint,
        public readonly string $idempotencyKey,
    ) {
    }
}
