<?php

namespace App\Services;

use App\DTO\TransferEventDTO;
use Illuminate\Support\Facades\Validator;

class TransferEventValidationService
{
    public function validateAndParse(array $rawEvents): array
    {
        $valid = [];
        $invalidRows = [];

        foreach ($rawEvents as $idx => $raw) {
            $payload = is_array($raw) ? $raw : ['_raw' => $raw];

            $v = Validator::make($payload, [
                'event_id' => ['required', 'string', 'max:64'],
                'station_id' => ['required', 'string', 'max:64'],
                'amount' => ['required', 'numeric', 'min:0'],
                'status' => ['required', 'string', 'max:32'],
                'created_at' => ['required', 'date'],
            ]);

            if ($v->fails()) {
                $invalidRows[] = $this->buildInvalidRow($payload, $idx, $v->errors()->toArray());
                continue;
            }

            $valid[] = TransferEventDTO::fromArray([
                'event_id' => (string) $payload['event_id'],
                'station_id' => (string) $payload['station_id'],
                'amount' => $payload['amount'],
                'status' => (string) $payload['status'],
                'created_at' => (string) $payload['created_at'],
            ]);
        }

        return [$valid, $invalidRows];
    }

    private function buildInvalidRow(array $payload, int $index, array $errors): array
    {
        $now = now();

        return [
            'failure_type' => 'invalid',
            'event_id' => $payload['event_id'] ?? null,
            'station_id' => $payload['station_id'] ?? null,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'error' => json_encode(['errors' => $errors, 'index' => $index], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}

