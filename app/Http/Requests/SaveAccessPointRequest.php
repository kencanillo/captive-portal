<?php

namespace App\Http\Requests;

use App\Models\AccessPoint;
use App\Rules\MacAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAccessPointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $accessPointId = $this->route('accessPoint')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255', Rule::unique('access_points', 'serial_number')->ignore($accessPointId)],
            'mac_address' => ['required', 'string', new MacAddress(), Rule::unique('access_points', 'mac_address')->ignore($accessPointId)],
            'site_name' => ['nullable', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'omada_device_id' => ['nullable', 'string', 'max:255', Rule::unique('access_points', 'omada_device_id')->ignore($accessPointId)],
            'claim_status' => ['required', Rule::in([
                AccessPoint::CLAIM_STATUS_UNCLAIMED,
                AccessPoint::CLAIM_STATUS_PENDING,
                AccessPoint::CLAIM_STATUS_CLAIMED,
                AccessPoint::CLAIM_STATUS_ERROR,
            ])],
            'custom_ssid' => ['nullable', 'string', 'max:255'],
            'voucher_ssid_name' => ['nullable', 'string', 'max:255'],
            'allow_client_pause' => ['required', 'boolean'],
            'block_tethering' => ['required', 'boolean'],
            'is_portal_enabled' => ['required', 'boolean'],
            'is_online' => ['sometimes', 'boolean'],
        ];
    }
}
