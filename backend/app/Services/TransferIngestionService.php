<?php

namespace App\Services;

use App\DTO\IngestTransfersDTO;
use App\DTO\TransferEventDTO;
use App\Jobs\ApplyStationSummaryIncrementsJob;
use App\Repositories\InvalidEventRepository;
use App\Repositories\TransferEventRepositoryInterface;

class TransferIngestionService
{
    public function __construct(
        private readonly TransferEventRepositoryInterface $repository,
        private readonly IngestionLockService $lockService,
        private readonly InvalidEventRepository $invalidEventRepository,
        private readonly IngestionMetricsService $metrics,
        private readonly TransferEventValidationService $validationService,
    )
    {
    }

    public function ingest(IngestTransfersDTO $dto): array
    {
        $t0 = microtime(true);
        $this->metrics->increment('received', $dto->count());

        [$valid, $invalidRows] = $this->validationService->validateAndParse($dto->rawEvents);
        $invalidCount = count($invalidRows);
        $this->writeInvalidRows($invalidRows);

        if ($valid === []) {
            return $this->finish($t0, inserted: 0, duplicates: 0, invalid: $invalidCount, failed: 0);
        }

        try {
            $eventIds = array_map(static fn (TransferEventDTO $e) => $e->eventId, $valid);
            $lockKeys = $this->lockService->keysForEventIds($eventIds);
            $result = $this->lockService->withLocks($lockKeys, fn () => $this->ingestValidatedEvents($valid));
            return $this->finish($t0, inserted: $result['inserted'], duplicates: $result['duplicates'], invalid: $invalidCount, failed: 0);
        } catch (\Throwable $e) {
            $failedCount = $this->writeFailedRows($valid, $e);
            $this->metrics->increment('failed', $failedCount);
            return $this->finish($t0, inserted: 0, duplicates: 0, invalid: $invalidCount, failed: $failedCount);
        }
    }

    private function writeInvalidRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->invalidEventRepository->insert($rows);
        $this->metrics->increment('invalid', count($rows));
    }

    private function ingestValidatedEvents(array $valid): array
    {
        $uniqueById = [];
        foreach ($valid as $e) {
            $uniqueById[$e->eventId] ??= $e;
        }
        $uniqueDtos = array_values($uniqueById);

        $now = now();
        $insertRows = array_map(
            static fn (TransferEventDTO $e): array => $e->toInsertRow($now),
            $uniqueDtos,
        );

        $eventIds = array_map(static fn (TransferEventDTO $e) => $e->eventId, $uniqueDtos);
        $preExisting = array_flip($this->repository->existingEventIds($eventIds));

        $inserted = $this->repository->insertIgnore($insertRows);

        $postExisting = $this->repository->existingEventIds($eventIds);
        $newlyInsertedIds = array_flip(
            array_filter($postExisting, static fn (string $id) => !isset($preExisting[$id]))
        );

        $newPayloads = [];
        foreach ($uniqueDtos as $e) {
            if (isset($newlyInsertedIds[$e->eventId])) {
                $newPayloads[] = [
                    'event_id' => $e->eventId,
                    'station_id' => $e->stationId,
                    'amount' => $e->amount,
                    'status' => $e->status,
                    'created_at' => $e->createdAt->toISOString(),
                ];
            }
        }

        ApplyStationSummaryIncrementsJob::dispatch($newPayloads);

        $duplicates = count($valid) - $inserted;

        return ['inserted' => $inserted, 'duplicates' => $duplicates];
    }

    private function writeFailedRows(array $valid, \Throwable $e): int
    {
        $now = now();

        $rows = array_map(static function (TransferEventDTO $event) use ($e, $now) {
            $payload = [
                'event_id' => $event->eventId,
                'station_id' => $event->stationId,
                'amount' => $event->amount,
                'status' => $event->status,
                'created_at' => $event->createdAt->toISOString(),
            ];

            return [
                'failure_type' => 'failed',
                'event_id' => $event->eventId,
                'station_id' => $event->stationId,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'error' => json_encode(['message' => $e->getMessage(), 'class' => get_class($e)], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $valid);

        $this->invalidEventRepository->insert($rows);
        return count($rows);
    }

    private function finish(float $t0, int $inserted, int $duplicates, int $invalid, int $failed): array
    {
        $this->metrics->increment('inserted', $inserted);
        $this->metrics->increment('duplicates', $duplicates);
        $this->metrics->timingMs('duration_ms', (microtime(true) - $t0) * 1000.0);

        return [
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
            'failed' => $failed,
        ];
    }
}

