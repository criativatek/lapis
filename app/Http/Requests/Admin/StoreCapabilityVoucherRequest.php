<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCapabilityVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['label' => ['required', 'string', 'max:160'], 'code' => ['nullable', 'string', 'max:64'], 'preset_id' => ['nullable', 'integer', Rule::exists('capability_grant_presets', 'id')->where('active', true)], 'duration_days' => ['required', 'integer', 'min:1', 'max:3650'], 'module_keys' => ['required_without:preset_id', 'array', 'min:1'], 'module_keys.*' => ['string', 'distinct', 'exists:modules,key'], 'valid_from' => ['nullable', 'date'], 'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'], 'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:100000'], 'restricted_organization_id' => ['nullable', 'integer', 'exists:organizations,id'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
