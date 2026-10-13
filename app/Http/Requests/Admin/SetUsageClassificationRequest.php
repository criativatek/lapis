<?php

namespace App\Http\Requests\Admin;

use App\Support\Usage\UsageClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A rota passa por `usage-insights`; a autorização não se repete aqui.
 */
class SetUsageClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'classification' => ['required', Rule::enum(UsageClassification::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
