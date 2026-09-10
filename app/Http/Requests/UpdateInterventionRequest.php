<?php

namespace App\Http\Requests;

use App\Models\InterventionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInterventionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'intervention_type' => ['required', Rule::enum(InterventionType::class)],
            'intervention_types' => ['prohibited'],
        ];
    }
}
