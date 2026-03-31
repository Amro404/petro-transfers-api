<?php

namespace App\Http\Controllers;

use App\DTO\IngestTransfersDTO;
use App\Http\Requests\IngestTransfersRequest;
use App\Services\TransferIngestionService;
use Illuminate\Http\JsonResponse;

class TransferController extends Controller
{
    public function __construct(private readonly TransferIngestionService $service)
    {
    }

    public function store(IngestTransfersRequest $request): JsonResponse
    {
        $dto = IngestTransfersDTO::fromValidated($request->validated());
        $result = $this->service->ingest($dto);

        return response()->json($result);
    }
}
