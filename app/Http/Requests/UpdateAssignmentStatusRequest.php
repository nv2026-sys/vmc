<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssignmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('status');
        if (! is_string($status)) {
            return;
        }

        $status = strtoupper(str_replace([' ', '-'], '_', trim($status)));
        $status = match ($status) {
            'STARTED' => 'EN_ROUTE',
            'REACHED', 'ON_SITE' => 'ARRIVED',
            'FINISHED' => 'COMPLETED',
            default => $status,
        };

        $this->merge(['status' => $status]);
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    'ASSIGNED',
                    'EN_ROUTE',
                    'ARRIVED',
                    'STARTED',
                    'IN_PROGRESS',
                    'COMPLETED',
                    'WORK_DONE',
                ]),
            ],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
