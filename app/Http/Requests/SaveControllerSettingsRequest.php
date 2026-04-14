<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveControllerSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'controller_name' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'url', 'max:255'],
            'site_identifier' => ['nullable', 'string', 'max:255'],
            'site_name' => ['nullable', 'string', 'max:255'],
            'portal_base_url' => ['nullable', 'url', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'api_client_id' => ['nullable', 'string', 'max:255'],
            'api_client_secret' => ['nullable', 'string', 'max:255'],
            'default_session_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
        ];
    }
}
