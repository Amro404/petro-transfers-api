<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ConcurrencyIngestionTest extends TestCase
{
    public function test_concurrent_ingestion_of_same_ids_does_not_double_insert(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is required for this concurrency test.');
        }

        // Use a real sqlite file so forked processes share the same DB.
        $dbFile = storage_path('framework/testing-concurrency.sqlite');
        @unlink($dbFile);
        touch($dbFile);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $dbFile,
            // Ensure Cache::lock coordinates across forked processes.
            'cache.default' => 'file',
            'transfers.lock_store' => 'file',
        ]);

        $cachePath = storage_path('framework/testing/cache-concurrency');
        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0777, true);
        }
        chmod($cachePath, 0777);

        config([
            'cache.stores.file.path' => $cachePath,
            'cache.stores.file.lock_path' => $cachePath,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::clear();

        Artisan::call('migrate:fresh', ['--force' => true]);

        $payload = [
            'events' => [
                [
                    'event_id' => 'evt-concurrent-1',
                    'station_id' => 'S9',
                    'amount' => 12.34,
                    'status' => 'approved',
                    'created_at' => '2026-02-19T10:00:00Z',
                ],
            ],
        ];

        $logFile = storage_path('framework/concurrency-child.log');
        @unlink($logFile);

        $spawn = function () use ($payload, $logFile): int {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }

            if ($pid === 0) {
                // Child: ensure a fresh connection in this process.
                DB::purge('sqlite');
                DB::reconnect('sqlite');

                $res = $this->postJson('/api/transfers', $payload);
                @file_put_contents($logFile, getmypid()." status=".$res->getStatusCode()." body=".$res->getContent().PHP_EOL, FILE_APPEND);
                exit(0);
            }

            return $pid;
        };

        $pid1 = $spawn();
        $pid2 = $spawn();

        pcntl_waitpid($pid1, $status1);
        pcntl_waitpid($pid2, $status2);

        $this->assertFileExists($logFile);

        $this->assertDatabaseCount('transfer_events', 1);

        $summary = $this->getJson('/api/stations/S9/summary')->assertOk()->json();
        $this->assertSame(1, $summary['events_count']);
        $this->assertEquals(12.34, $summary['total_approved_amount']);
    }
}

