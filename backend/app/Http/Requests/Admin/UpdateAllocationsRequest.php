<?php

namespace App\Http\Requests\Admin;

use App\Enums\Allocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateAllocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('manage-allocations');
    }

    public function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1', 'max:100'],
            'changes.*' => ['array'],
            'changes.*.block_id' => ['required', 'integer', 'distinct'],
            'changes.*.expected' => ['required', Rule::enum(Allocation::class)],
            'changes.*.allocation' => ['required', Rule::enum(Allocation::class), 'different:changes.*.expected'],
        ];
    }
}
