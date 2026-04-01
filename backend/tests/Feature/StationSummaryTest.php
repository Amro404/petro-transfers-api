<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_endpoint_correctness_per_station(): void
    {
        $this->postJson('/api/transfers', [
            'events' => [
                [
                    'event_id' => 'evt-s1-1',
                    'station_id' => 'S1',
                    'amount' => 10.00,
                    'status' => 'approved',
                    'created_at' => '2026-02-19T10:00:00Z',
                ],
                [
                    'event_id' => 'evt-s1-2',
                    'station_id' => 'S1',
                    'amount' => 5.00,
                    'status' => 'pending',
                    'created_at' => '2026-02-19T10:00:01Z',
                ],
                [
                    'event_id' => 'evt-s2-1',
                    'station_id' => 'S2',
                    'amount' => 99.00,
                    'status' => 'approved',
                    'created_at' => '2026-02-19T10:00:02Z',
                ],
            ],
        ])->assertOk();

        $s1 = $this->getJson('/api/stations/S1/summary')->assertOk()->json();
        $this->assertSame('S1', $s1['station_id']);
        $this->assertSame(2, $s1['events_count']);
        $this->assertEquals(10.0, $s1['total_approved_amount']);

        $s2 = $this->getJson('/api/stations/S2/summary')->assertOk()->json();
        $this->assertSame('S2', $s2['station_id']);
        $this->assertSame(1, $s2['events_count']);
        $this->assertEquals(99.0, $s2['total_approved_amount']);
    }

    public function test_summary_for_station_with_no_events_returns_zeros(): void
    {
        $s0 = $this->getJson('/api/stations/EMPTY/summary')->assertOk()->json();
        $this->assertSame('EMPTY', $s0['station_id']);
        $this->assertSame(0, $s0['events_count']);
        $this->assertEquals(0.0, $s0['total_approved_amount']);
    }

    public function test_live_summary_computes_from_transfer_events(): void
    {
        $this->postJson('/api/transfers', [
            'events' => [
                [
                    'event_id' => 'evt-live-1',
                    'station_id' => 'L1',
                    'amount' => 25.50,
                    'status' => 'approved',
                    'created_at' => '2026-03-01T10:00:00Z',
                ],
                [
                    'event_id' => 'evt-live-2',
                    'station_id' => 'L1',
                    'amount' => 14.50,
                    'status' => 'approved',
                    'created_at' => '2026-03-01T10:00:01Z',
                ],
                [
                    'event_id' => 'evt-live-3',
                    'station_id' => 'L1',
                    'amount' => 100.00,
                    'status' => 'declined',
                    'created_at' => '2026-03-01T10:00:02Z',
                ],
                [
                    'event_id' => 'evt-live-4',
                    'station_id' => 'L2',
                    'amount' => 50.00,
                    'status' => 'approved',
                    'created_at' => '2026-03-01T10:00:03Z',
                ],
            ],
        ])->assertOk();

        $l1 = $this->getJson('/api/stations/L1/summary/live')->assertOk()->json();
        $this->assertSame('L1', $l1['station_id']);
        $this->assertSame(3, $l1['events_count']);
        $this->assertEquals(40.0, $l1['total_approved_amount']);

        $l2 = $this->getJson('/api/stations/L2/summary/live')->assertOk()->json();
        $this->assertSame('L2', $l2['station_id']);
        $this->assertSame(1, $l2['events_count']);
        $this->assertEquals(50.0, $l2['total_approved_amount']);
    }

    public function test_live_summary_for_station_with_no_events_returns_zeros(): void
    {
        $res = $this->getJson('/api/stations/NONE/summary/live')->assertOk()->json();
        $this->assertSame('NONE', $res['station_id']);
        $this->assertSame(0, $res['events_count']);
        $this->assertEquals(0.0, $res['total_approved_amount']);
    }

    public function test_live_summary_matches_cached_summary(): void
    {
        $this->postJson('/api/transfers', [
            'events' => [
                [
                    'event_id' => 'evt-cmp-1',
                    'station_id' => 'CMP',
                    'amount' => 75.25,
                    'status' => 'approved',
                    'created_at' => '2026-03-01T12:00:00Z',
                ],
                [
                    'event_id' => 'evt-cmp-2',
                    'station_id' => 'CMP',
                    'amount' => 24.75,
                    'status' => 'pending',
                    'created_at' => '2026-03-01T12:00:01Z',
                ],
            ],
        ])->assertOk();

        $cached = $this->getJson('/api/stations/CMP/summary')->assertOk()->json();
        $live = $this->getJson('/api/stations/CMP/summary/live')->assertOk()->json();

        $this->assertSame($cached['station_id'], $live['station_id']);
        $this->assertSame($cached['events_count'], $live['events_count']);
        $this->assertEquals($cached['total_approved_amount'], $live['total_approved_amount']);
    }
}

