<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Raised when an event with the same idempotency key has already been
 * processed. Used by the Application layer to short-circuit billing
 * on retried/duplicated requests (network retries, at-least-once queues, etc.).
 */
final class DuplicateEventException extends \RuntimeException
{
}
