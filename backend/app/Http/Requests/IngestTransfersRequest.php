<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IngestTransfersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1'],
        ];
    }
}
