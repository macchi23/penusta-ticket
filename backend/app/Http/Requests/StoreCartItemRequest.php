<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seat_id' => ['required_without:block_id', 'prohibits:block_id', 'integer', 'exists:seats,id'],
            'block_id' => ['required_without:seat_id', 'prohibits:seat_id', 'integer', 'exists:blocks,id'],
            'ticket_type_id' => ['nullable', 'integer', 'exists:ticket_types,id'],
        ];
    }
}
