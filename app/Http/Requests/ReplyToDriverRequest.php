<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReplyToDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignment_id' => ['required', 'integer', 'exists:job_assignments,id'],
            'reply' => ['required', 'string', 'max:2000'],
        ];
    }
}
