<?php

declare(strict_types=1);

namespace App\Application\DTO;

final readonly class LogEventCommand
{
    public function __construct(
        public string $clientId,
        public string $endpoint,
        public string $idempotencyKey,
    ) {
    }
}
