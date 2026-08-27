<?php

namespace App\Http\Requests\Admin;

use App\Models\CommercialCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Marks the commercial condition of an existing subscription.
 *
 * `condition` is nullable on purpose: clearing it back to "origem não
 * registada" must be possible, because an operator who marked an account
 * Fundador by mistake needs a way to say "I do not actually know" rather than
 * being forced to pick a different wrong answer.
 */
class SetCommercialConditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'condition' => ['nullable', Rule::enum(CommercialCondition::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
