<?php

namespace App\Repositories;

interface TransferEventRepositoryInterface
{
    public function insertIgnore(array $rows): int;

    public function existingEventIds(array $eventIds): array;

    public function applyStationSummaryIncrements(array $incrementsByStation): void;

    public function stationSummary(string $stationId): array;
}

