<?php

namespace App\Http\Requests;

use App\Models\InterventionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInterventionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('intervention_types') && $this->filled('intervention_type')) {
            $this->merge(['intervention_types' => [$this->input('intervention_type')]]);
            ($this->isJson() ? $this->json() : $this->request)->remove('intervention_type');
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'intervention_types' => ['required', 'array', 'min:1', 'max:10'],
            'intervention_types.*' => ['required', 'distinct', Rule::enum(InterventionType::class)],
        ];
    }
}
