<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreDirectCapabilityGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['module_keys' => ['required', 'array', 'min:1'], 'module_keys.*' => ['required', 'string', 'distinct', 'exists:modules,key'], 'duration_days' => ['required', 'integer', 'min:1', 'max:3650'], 'reason' => ['required', 'string', 'max:500']];
    }
}
