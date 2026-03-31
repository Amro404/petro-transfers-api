<?php

namespace App\Http\Controllers;

use App\Services\StationSummaryService;
use Illuminate\Http\JsonResponse;

class StationSummaryController extends Controller
{
    public function __construct(private readonly StationSummaryService $service)
    {
    }

    public function show(string $station_id): JsonResponse
    {
        return response()->json($this->service->summary($station_id));
    }
}
