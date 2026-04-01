<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

class IngestionLockService
{
    public function withLocks(array $keys, Closure $callback, int $seconds = 10, int $waitSeconds = 3): mixed
    {
        $locks = [];
        $keys = array_values(array_unique($keys));
        sort($keys);

        try {
            $storeName = config('transfers.lock_store') ?: config('cache.default');
            $store = Cache::store($storeName);

            if (! method_exists($store->getStore(), 'lock')) {
                return $callback();
            }

            foreach ($keys as $key) {
                $lock = $store->lock($key, $seconds);
                $acquired = $lock->block($waitSeconds);
                if (! $acquired) {
                    throw new \RuntimeException("Failed to acquire lock: {$key}");
                }
                $locks[] = $lock;
            }

            return $callback();
        } finally {
            for ($i = count($locks) - 1; $i >= 0; $i--) {
                try {
                    $locks[$i]->release();
                } catch (Throwable) {
                }
            }
        }
    }

    public function keysForEventIds(array $eventIds, int $bucketCount = 256): array
    {
        $keys = [];
        foreach ($eventIds as $eventId) {
            $bucket = (int) (sprintf('%u', crc32($eventId)) % $bucketCount);
            $keys[] = "locks:transfers:ingest:b{$bucket}";
        }
        return $keys;
    }
}

