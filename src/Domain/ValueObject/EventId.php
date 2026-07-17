<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

final readonly class EventId
{
    private string $value;

    public function __construct(string $value)
    {
        if (!preg_match('/^[a-f0-9\-]{36}$/i', $value)) {
            throw new \InvalidArgumentException(sprintf('Invalid event id: "%s"', $value));
        }

        $this->value = $value;
    }

    public static function generate(): self
    {
        // RFC 4122 v4 UUID, pure PHP - zero framework dependency in Domain.
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return new self(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)));
    }

    public function value(): string
    {
        return $this->value;
    }
}
