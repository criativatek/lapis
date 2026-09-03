<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCapabilityPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['key' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:capability_grant_presets,key'], 'name' => ['required', 'string', 'max:160'], 'notes' => ['nullable', 'string', 'max:2000'], 'default_duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'], 'module_keys' => ['required', 'array', 'min:1'], 'module_keys.*' => ['required', 'string', 'distinct', 'exists:modules,key']];
    }
}
