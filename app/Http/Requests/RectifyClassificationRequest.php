<?php

namespace App\Http\Requests;

use App\Models\ClassificationScope;
use App\Models\RectificationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rectifying a published classification: the shape of the request only.
 *
 * Whether THIS user may rectify THIS classification is not asked here — it
 * depends on the classification's organization and on the period's state, which
 * the controller has to find first and the policy decides (ClassificationPolicy).
 */
class RectifyClassificationRequest extends FormRequest
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
            'scope' => ['nullable', Rule::enum(ClassificationScope::class)],
            'reason' => ['required', Rule::enum(RectificationReason::class)],
            // Required for «Outro»; the service owns that rule, as it owns the others.
            'note' => ['nullable', 'string', 'max:1000'],
            'final_scale_level_id' => ['nullable', 'integer'],
            // Same limits as `decide`: `decimal` rejects "1e2", which would pass
            // numeric+between and blow up bcmath. The scale's own bounds are the service's.
            'final_value' => ['nullable', 'decimal:0,3', 'between:0,999.999'],
            // The version the dialog was opened on; a stale one means someone got there first.
            'lock_version' => ['required', 'integer', 'min:0'],
            'closed_confirmed' => ['boolean'],
        ];
    }
}
