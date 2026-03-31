<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvalidEvent extends Model
{
    protected $table = 'invalid_events';

    protected $fillable = [
        'failure_type',
        'event_id',
        'station_id',
        'payload',
        'error',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'error' => 'array',
        'occurred_at' => 'datetime',
    ];
}

