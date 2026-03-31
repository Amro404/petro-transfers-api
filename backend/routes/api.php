<?php

use App\Http\Controllers\StationSummaryController;
use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;

Route::post('/transfers', [TransferController::class, 'store']);
Route::get('/stations/{station_id}/summary', [StationSummaryController::class, 'show']);

