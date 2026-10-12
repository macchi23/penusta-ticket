<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreResaleListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // 範囲(下限・定価)の判定は ResaleService が券ごとに行う
        return [
            'price' => ['required', 'integer'],
        ];
    }
}
