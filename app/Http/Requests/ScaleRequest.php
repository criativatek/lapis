<?php

namespace App\Http\Requests;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScaleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('scales', 'name')
                    ->where('organization_id', app(CurrentOrganization::class)->id()),
            ],
            'min_value' => ['required', 'numeric'],
            'max_value' => ['required', 'numeric', 'gt:min_value'],
        ];
    }
}
