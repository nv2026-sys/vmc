<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'latitude' => ['sometimes', 'required_with:longitude,speed_kph,recorded_at', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required_with:latitude,speed_kph,recorded_at', 'numeric', 'between:-180,180'],
            'speed_kph' => ['sometimes', 'nullable', 'numeric', 'between:0,300'],
            'recorded_at' => [
                'sometimes',
                'nullable',
                'date',
                'after_or_equal:'.now()->subDay()->toIso8601String(),
                'before_or_equal:'.now()->addMinutes(5)->toIso8601String(),
            ],
        ];
    }
}
