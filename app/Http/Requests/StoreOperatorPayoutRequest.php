<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOperatorPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'destination_type' => ['required', 'string', 'max:50'],
            'destination_account_name' => ['required', 'string', 'max:255'],
            'destination_account_reference' => ['required', 'string', 'max:255'],
            'destination_provider' => ['nullable', 'string', 'max:100'],
            'destination_bic' => ['nullable', 'string', 'max:50'],
            'destination_notes' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
