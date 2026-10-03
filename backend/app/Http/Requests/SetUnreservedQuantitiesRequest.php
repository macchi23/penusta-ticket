<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetUnreservedQuantitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantities' => ['required', 'array', 'min:1', 'max:10'],
            'quantities.*.ticket_type_id' => ['bail', 'required', 'integer', 'distinct', 'exists:ticket_types,id'],
            'quantities.*.quantity' => ['bail', 'required', 'integer', 'min:0'],
        ];
    }
}
