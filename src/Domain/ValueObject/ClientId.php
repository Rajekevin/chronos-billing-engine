<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use App\Domain\Exception\InvalidClientIdException;

final readonly class ClientId
{
    private string $value;

    public function __construct(string $value)
    {
        if (!preg_match('/^[a-zA-Z0-9\-]{8,64}$/', $value)) {
            throw new InvalidClientIdException(
                sprintf('Invalid client identifier format: "%s"', $value)
            );
        }

        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
