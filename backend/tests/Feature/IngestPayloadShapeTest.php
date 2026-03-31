<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestPayloadShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_payload_shape_returns_400(): void
    {
        $res = $this->postJson('/api/transfers', ['foo' => 'bar']);
        $res->assertStatus(400);
        $res->assertJsonStructure(['message', 'errors']);
    }
}

