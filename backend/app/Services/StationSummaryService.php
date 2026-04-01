<?php

namespace App\Services;

use App\Repositories\TransferEventRepositoryInterface;

class StationSummaryService
{
    public function __construct(private readonly TransferEventRepositoryInterface $repository)
    {
    }

    public function summary(string $stationId): array
    {
        return $this->repository->stationSummary($stationId);
    }

    public function summaryLive(string $stationId): array
    {
        return $this->repository->stationSummaryLive($stationId);
    }
}

