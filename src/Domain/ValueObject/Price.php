<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

final readonly class Price
{
    private int $amountInCents;

    public function __construct(int $amountInCents)
    {
        if ($amountInCents < 0) {
            throw new \InvalidArgumentException('Price cannot be negative.');
        }

        $this->amountInCents = $amountInCents;
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public function amountInCents(): int
    {
        return $this->amountInCents;
    }

    public function add(self $other): self
    {
        return new self($this->amountInCents + $other->amountInCents);
    }

    public function multiplyBy(int $factor): self
    {
        return new self($this->amountInCents * $factor);
    }
}
