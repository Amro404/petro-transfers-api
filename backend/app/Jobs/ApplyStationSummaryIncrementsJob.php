<?php

namespace App\Jobs;

use App\Repositories\TransferEventRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplyStationSummaryIncrementsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly array $newEventPayloads,
    ) {}

    public function handle(TransferEventRepositoryInterface $repository): void
    {
        if ($this->newEventPayloads === []) {
            return;
        }

        $incrementsByStation = [];
        foreach ($this->newEventPayloads as $payload) {
            $stationId = $payload['station_id'];
            $incrementsByStation[$stationId] ??= ['events' => 0, 'approved_amount' => '0'];

            $incrementsByStation[$stationId]['events']++;
            if ($payload['status'] === 'approved') {
                $incrementsByStation[$stationId]['approved_amount'] = bcadd(
                    $incrementsByStation[$stationId]['approved_amount'],
                    (string) $payload['amount'],
                    2,
                );
            }
        }

        if ($incrementsByStation !== []) {
            $repository->applyStationSummaryIncrements($incrementsByStation);
        }
    }
}

