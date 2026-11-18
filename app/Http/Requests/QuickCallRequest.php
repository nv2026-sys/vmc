<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuickCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('jobs.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'caller_name' => ['required', 'string', 'max:255'],
            'caller_phone' => ['required', 'string', 'min:7', 'max:32', 'regex:/^[0-9+().\\-\\s]+$/'],
            'incident_type' => ['required', 'in:STRUCTURE_FIRE,VEHICLE_FIRE,ROAD_ACCIDENT,MEDICAL_EMERGENCY,RESCUE,OTHER'],
            'priority' => ['required', 'in:LOW,NORMAL,HIGH,CRITICAL'],
            'ward_id' => ['required', 'integer', 'exists:wards,id'],
            'society_name' => ['required', 'string', 'max:255'],
            'incident_address' => ['required', 'string', 'max:2000'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
