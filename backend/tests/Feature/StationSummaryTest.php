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
}

