<?php

namespace App\Http\Requests;

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
            'portal_token' => ['required', 'string'],
            'device_decision' => ['nullable', 'string', 'in:pay,transfer'],
            'client_registration' => ['nullable', 'array'],
            'client_registration.name' => ['required_with:client_registration', 'string', 'max:255'],
            'client_registration.phone_number' => ['required_with:client_registration', 'string', 'max:20'],
            'client_registration.pin' => ['required_with:client_registration', 'string', 'min:4', 'max:20'],
            'client_registration.pin_confirmation' => ['required_with:client_registration', 'same:client_registration.pin'],
            'new_pin' => ['nullable', 'required_if:device_decision,pay', 'string', 'min:4', 'max:20', 'different:client_registration.pin'],
            'new_pin_confirmation' => ['nullable', 'required_if:device_decision,pay', 'same:new_pin'],
        ];
    }

    public function getClientRegistrationData(): ?array
    {
        $registrationData = $this->validated()['client_registration'] ?? null;

        if (! $registrationData) {
            return null;
        }

        unset($registrationData['pin_confirmation']);

        return $registrationData;
    }

    public function getDeviceOptions(): array
    {
        return [
            'device_decision' => $this->validated()['device_decision'] ?? null,
            'new_pin' => $this->validated()['new_pin'] ?? null,
        ];
    }
}
