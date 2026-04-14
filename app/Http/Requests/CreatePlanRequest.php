<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:1'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'speed_limit' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'supports_pause' => ['required', 'boolean'],
            'enforce_no_tethering' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
