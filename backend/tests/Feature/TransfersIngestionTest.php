<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransfersIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_insert_returns_correct_inserted_and_duplicates(): void
    {
        $payload = [
            'events' => [
                [
                    'event_id' => 'evt-1',
                    'station_id' => 'S1',
                    'amount' => 10.50,
                    'status' => 'approved',
                    'created_at' => '2026-02-19T10:00:00Z',
                ],
                [
                    'event_id' => 'evt-2',
                    'station_id' => 'S1',
                    'amount' => 5,
                    'status' => 'declined',
                    'created_at' => '2026-02-19T10:01:00Z',
                ],
                // duplicate inside the same payload
                [
                    'event_id' => 'evt-2',
                    'station_id' => 'S1',
                    'amount' => 5,
                    'status' => 'declined',
                    'created_at' => '2026-02-19T10:01:00Z',
                ],
            ],
        ];

        $res = $this->postJson('/api/transfers', $payload);

        $res->assertOk()->assertJson([
            'inserted' => 2,
            'duplicates' => 1,
            'invalid' => 0,
            'failed' => 0,
        ]);

        $this->assertDatabaseCount('transfer_events', 2);
    }

    public function test_duplicate_event_does_not_change_totals(): void
    {
        $payload = [
            'events' => [
                [
                    'event_id' => 'evt-10',
                    'station_id' => 'S1',
                    'amount' => 100.00,
                    'status' => 'approved',
                    'created_at' => '2026-02-19T10:00:00Z',
                ],
            ],
        ];

        $this->postJson('/api/transfers', $payload)->assertOk()->assertJson([
            'inserted' => 1,
            'duplicates' => 0,
            'invalid' => 0,
            'failed' => 0,
        ]);

        // replay same event_id (even if fields differ, we must not overwrite)
        $payloadReplay = [
            'events' => [
                [
                    'event_id' => 'evt-10',
                    'station_id' => 'S1',
                    'amount' => 999.99,
                    'status' => 'approved',
                    'created_at' => '2026-02-20T10:00:00Z',
                ],
            ],
        ];

        $this->postJson('/api/transfers', $payloadReplay)->assertOk()->assertJson([
            'inserted' => 0,
            'duplicates' => 1,
            'invalid' => 0,
            'failed' => 0,
        ]);

        $summary = $this->getJson('/api/stations/S1/summary')->assertOk()->json();
        $this->assertSame('S1', $summary['station_id']);
        $this->assertSame(1, $summary['events_count']);
        $this->assertEquals(100.0, $summary['total_approved_amount']);
    }

    public function test_out_of_order_arrival_still_produces_same_totals(): void
    {
        $a = [
            'events' => [
                [
                    'event_id' => 'evt-a',
                    'station_id' => 'S2',
                    'amount' => 20.00,
                    'status' => 'approved',
                    'created_at' => '2026-02-19T10:02:00Z',
                ],
            ],
        ];

        $b = [
            'events' => [
                [
                    'event_id' => 'evt-b',
                    'station_id' => 'S2',
                    'amount' => 30.00,
                    'status' => 'approved',
                    'created_at' => '2026-02-19T10:01:00Z',
                ],
            ],
        ];

        // ingest out of chronological order
        $this->postJson('/api/transfers', $a)->assertOk();
        $this->postJson('/api/transfers', $b)->assertOk();

        $summary = $this->getJson('/api/stations/S2/summary')->assertOk()->json();
        $this->assertSame(2, $summary['events_count']);
        $this->assertEquals(50.0, $summary['total_approved_amount']);
    }

    public function test_validation_failure_returns_400_with_errors(): void
    {
        $payload = [
            'events' => [
                [
                    'station_id' => 'S1',
                    'amount' => -1,
                    'status' => 'approved',
                    'created_at' => 'not-a-date',
                ],
            ],
        ];

        $res = $this->postJson('/api/transfers', $payload);
        $res->assertOk()->assertJson([
            'inserted' => 0,
            'duplicates' => 0,
            'invalid' => 1,
            'failed' => 0,
        ]);

        $this->assertDatabaseCount('invalid_events', 1);
    }

    public function test_mixed_valid_and_invalid_batch_inserts_valid_only(): void
    {
        $payload = [
            'events' => [
                [
                    'event_id' => 'evt-good',
                    'station_id' => 'S1',
                    'amount' => 50.00,
                    'status' => 'approved',
                    'created_at' => '2026-02-19T10:00:00Z',
                ],
                [
                    'event_id' => 'evt-bad',
                    'station_id' => 'S1',
                    'amount' => -5,
                    'status' => 'approved',
                    'created_at' => 'not-a-date',
                ],
            ],
        ];

        $res = $this->postJson('/api/transfers', $payload);
        $res->assertOk()->assertJson([
            'inserted' => 1,
            'duplicates' => 0,
            'invalid' => 1,
            'failed' => 0,
        ]);

        $this->assertDatabaseCount('transfer_events', 1);
        $this->assertDatabaseCount('invalid_events', 1);

        $summary = $this->getJson('/api/stations/S1/summary')->assertOk()->json();
        $this->assertSame(1, $summary['events_count']);
        $this->assertEquals(50.0, $summary['total_approved_amount']);
    }

    public function test_unknown_status_is_stored_but_excluded_from_approved_total(): void
    {
        $payload = [
            'events' => [
                [
                    'event_id' => 'evt-approved',
                    'station_id' => 'S5',
                    'amount' => 75.00,
                    'status' => 'approved',
                    'created_at' => '2026-02-19T10:00:00Z',
                ],
                [
                    'event_id' => 'evt-unknown',
                    'station_id' => 'S5',
                    'amount' => 25.00,
                    'status' => 'pending_review',
                    'created_at' => '2026-02-19T10:01:00Z',
                ],
                [
                    'event_id' => 'evt-declined',
                    'station_id' => 'S5',
                    'amount' => 10.00,
                    'status' => 'declined',
                    'created_at' => '2026-02-19T10:02:00Z',
                ],
            ],
        ];

        $res = $this->postJson('/api/transfers', $payload);
        $res->assertOk()->assertJson([
            'inserted' => 3,
            'duplicates' => 0,
            'invalid' => 0,
            'failed' => 0,
        ]);

        $this->assertDatabaseCount('transfer_events', 3);

        $summary = $this->getJson('/api/stations/S5/summary')->assertOk()->json();
        $this->assertSame(3, $summary['events_count']);
        $this->assertEquals(75.0, $summary['total_approved_amount']);
    }

    public function test_amount_zero_is_valid(): void
    {
        $res = $this->postJson('/api/transfers', [
            'events' => [
                [
                    'event_id' => 'evt-zero',
                    'station_id' => 'S1',
                    'amount' => 0,
                    'status' => 'approved',
                    'created_at' => '2026-02-19T10:00:00Z',
                ],
            ],
        ]);

        $res->assertOk()->assertJson(['inserted' => 1, 'invalid' => 0]);
        $this->assertDatabaseCount('transfer_events', 1);
    }
}

