<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleOperationalStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                Vehicle::OPERATIONAL_STATUS_ON_ROAD,
                Vehicle::OPERATIONAL_STATUS_OFF_ROAD,
            ])],
        ];
    }
}
