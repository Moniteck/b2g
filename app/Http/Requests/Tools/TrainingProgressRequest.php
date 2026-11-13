<?php

namespace App\Http\Requests\Tools;

use App\Support\Tools\TrainingCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrainingProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tutorial_key' => ['required', 'string', Rule::in(TrainingCatalog::keys())],
            'completed_lessons' => ['required', 'integer', 'min:0', 'max:50'],
        ];
    }

    public function attributes(): array
    {
        return ['tutorial_key' => 'curso', 'completed_lessons' => 'lecciones completadas'];
    }
}
