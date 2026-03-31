<?php

namespace App\DTO;

final class IngestTransfersDTO
{
    /**
     * @param array<int, mixed> $rawEvents
     */
    public function __construct(public readonly array $rawEvents)
    {
    }

    /**
     * @param array{events:array<int,mixed>} $data
     */
    public static function fromValidated(array $data): self
    {
        return new self(rawEvents: $data['events']);
    }

    public function count(): int
    {
        return count($this->rawEvents);
    }
}

