<?php

namespace App\DTO;

use Carbon\CarbonImmutable;

final class TransferEventDTO
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $stationId,
        /** @var numeric-string */
        public readonly string $amount,
        public readonly string $status,
        public readonly CarbonImmutable $createdAt,
    ) {
    }

    /**
     * @param array{event_id:string,station_id:string,amount:int|float|string,status:string,created_at:string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            eventId: $data['event_id'],
            stationId: $data['station_id'],
            amount: (string) $data['amount'],
            status: $data['status'],
            createdAt: CarbonImmutable::parse($data['created_at']),
        );
    }

    public function toInsertRow(\DateTimeInterface $now): array
    {
        return [
            'event_id' => $this->eventId,
            'station_id' => $this->stationId,
            'amount' => $this->amount,
            'status' => $this->status,
            'created_at_event' => $this->createdAt,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}

