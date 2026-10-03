<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActivityEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['view_match', 'view_seatmap'])],
            'match_id' => ['required', 'integer', 'min:1', 'exists:matches,id'],
            'block_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', 'exists:blocks,code'],
        ];
    }
}
