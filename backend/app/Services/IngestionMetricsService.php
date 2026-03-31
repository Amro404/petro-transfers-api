<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class IngestionMetricsService
{
    public function increment(string $name, int $value = 1): void
    {
        try {
            Cache::store(config('cache.default'))->increment("metrics:ingestion:{$name}", $value);
        } catch (\Throwable $e) {
            Log::debug('metrics_increment_failed', ['name' => $name, 'error' => $e->getMessage()]);
        }
    }

    public function timingMs(string $name, float $ms): void
    {
        Log::info('ingestion_timing', ['metric' => $name, 'ms' => $ms]);
    }
}

