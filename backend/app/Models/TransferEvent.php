<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferEvent extends Model
{
    protected $table = 'transfer_events';

    protected $fillable = [
        'event_id',
        'station_id',
        'amount',
        'status',
        'created_at_event',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at_event' => 'datetime',
    ];
}
