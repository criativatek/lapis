<?php

namespace App\Http\Requests\Admin;

use App\Enums\SupportAccessReasonCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartSupportAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isPlatformAdmin() && $user->isSupportTechnician();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(SupportAccessReasonCategory::class)],
            'ticket_reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
