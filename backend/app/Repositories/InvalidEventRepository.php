<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class InvalidEventRepository
{
    public function insert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('invalid_events')->insert($chunk);
        }
    }
}

