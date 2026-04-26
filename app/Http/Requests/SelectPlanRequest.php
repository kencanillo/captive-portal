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
            'client_registration' => ['required', 'array'],
            'client_registration.name' => ['required', 'string', 'max:255'],
            'client_registration.phone_number' => ['required', 'string', 'max:20'],
            'client_registration.pin' => ['required', 'string', 'min:4', 'max:20'],
            'client_registration.pin_confirmation' => ['required', 'same:client_registration.pin'],
        ];
    }

    public function getClientRegistrationData(): array
    {
        $registrationData = $this->validated()['client_registration'];

        unset($registrationData['pin_confirmation']);

        return $registrationData;
    }
}
