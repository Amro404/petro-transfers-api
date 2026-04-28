<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class EloquentTransferEventRepository implements TransferEventRepositoryInterface
{
    public function insertIgnore(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $inserted = 0;
        foreach (array_chunk($rows, 1000) as $chunk) {
            $inserted += DB::table('transfer_events')->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    public function existingEventIds(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $ids = DB::table('transfer_events')
            ->whereIn('event_id', $eventIds)
            ->pluck('event_id')
            ->all();

        return $ids;
    }

    public function applyStationSummaryIncrements(array $incrementsByStation): void
    {
        ksort($incrementsByStation);

        foreach ($incrementsByStation as $stationId => $increment) {
            $events = (int) $increment['events'];
            $approvedAmount = (string) $increment['approved_amount'];
            $approvedEventsCount = (int) $increment['approved_events_count'];

            DB::transaction(function () use ($stationId, $events, $approvedAmount, $approvedEventsCount) {
                $now = now();

                DB::table('station_summaries')->insertOrIgnore([
                    'station_id' => $stationId,
                    'events_count' => 0,
                    'approved_events_count' => 0,
                    'total_approved_amount' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('station_summaries')
                    ->where('station_id', $stationId)
                    ->update([
                        'events_count' => DB::raw('events_count + '.$events),
                        'approved_events_count' => DB::raw('approved_events_count + '.$approvedEventsCount),
                        'total_approved_amount' => DB::raw('total_approved_amount + '.((float) $approvedAmount)),
                        'updated_at' => $now,
                    ]);
            }, 5);
        }
    }

    public function stationSummary(string $stationId): array
    {
        $row = DB::table('station_summaries')
            ->where('station_id', $stationId)
            ->select(['events_count', 'total_approved_amount'])
            ->first();

        return [
            'station_id' => $stationId,
            'total_approved_amount' => round((float) ($row->total_approved_amount ?? 0), 2),
            'events_count' => (int) ($row->events_count ?? 0),
        ];
    }

    public function stationSummaryLive(string $stationId): array
    {
        $row = DB::table('transfer_events')
            ->where('station_id', $stationId)
            ->selectRaw('COUNT(*) as events_count')
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END) as total_approved_amount")
            ->first();

        return [
            'station_id' => $stationId,
            'total_approved_amount' => round((float) ($row->total_approved_amount ?? 0), 2),
            'events_count' => (int) ($row->events_count ?? 0),
        ];
    }
}

