<?php

namespace App\Http\Requests;

use App\Rules\MacAddress;
use Illuminate\Foundation\Http\FormRequest;

class SelectPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'mac_address' => ['required', 'string', new MacAddress()],
            'ap_mac' => ['nullable', 'string', new MacAddress()],
            'ap_name' => ['nullable', 'string', 'max:120'],
            'site_name' => ['nullable', 'string', 'max:120'],
            'ssid_name' => ['nullable', 'string', 'max:120'],
            'radio_id' => ['nullable', 'integer', 'min:0', 'max:8'],
            'client_ip' => ['nullable', 'ip'],
            'client_registration' => ['nullable', 'array'],
            'client_registration.name' => ['required_with:client_registration', 'string', 'max:255'],
            'client_registration.phone_number' => ['required_with:client_registration', 'string', 'max:20'],
            'client_registration.pin' => ['required_with:client_registration', 'string', 'min:4', 'max:20'],
        ];
    }

    public function getClientRegistrationData(): ?array
    {
        return $this->validated()['client_registration'] ?? null;
    }
}
