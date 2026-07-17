<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * Deterministic key used to detect duplicate ingestion requests.
 * Callers are expected to derive this from a client-supplied
 * "Idempotency-Key" header, or Chronos falls back to a content hash.
 */
final readonly class IdempotencyKey
{
    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > 128) {
            throw new \InvalidArgumentException('Idempotency key must be between 1 and 128 characters.');
        }

        $this->value = $trimmed;
    }

    public static function fromPayload(string $clientId, string $endpoint, string $rawBody): self
    {
        return new self(hash('sha256', $clientId . '|' . $endpoint . '|' . $rawBody));
    }

    public function value(): string
    {
        return $this->value;
    }
}
