<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreOperatorRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone_number' => ['required', 'string', 'max:40'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'site_name_request' => ['nullable', 'string', 'max:255'],
            'payout_method' => ['nullable', 'string', 'max:120'],
            'payout_account_name' => ['nullable', 'string', 'max:255'],
            'payout_account_reference' => ['nullable', 'string', 'max:255'],
            'payout_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
