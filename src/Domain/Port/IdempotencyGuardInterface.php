<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\ValueObject\IdempotencyKey;

/**
 * Output port guarding the Application layer against double-processing
 * (and therefore double-billing) of the same logical event.
 *
 * The Infrastructure adapter is free to implement this atomically
 * (e.g. a DB unique constraint, a Redis SETNX) - the Domain only
 * cares about the contract: "has this key already been consumed?".
 */
interface IdempotencyGuardInterface
{
    public function hasBeenProcessed(IdempotencyKey $key): bool;

    public function markAsProcessed(IdempotencyKey $key): void;
}
