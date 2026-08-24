<?php

namespace App\Http\Requests\Lessons;

use App\Models\LessonSequence;
use App\Models\SchoolClass;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

class LessonSequenceApplyRequest extends FormRequest
{
    /**
     * Whether the actor teaches the chosen class, and whether the class is
     * actually compatible with the sequence (subject, grade_level), are both
     * checked by ApplyLessonSequence itself — the authoritative gate, not
     * duplicated here. This only confirms the sequence being applied is the
     * requester's own.
     */
    public function authorize(): bool
    {
        $sequence = $this->route('lessonSequence');

        return $sequence instanceof LessonSequence && $this->user()?->can('view', $sequence) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class_id' => ['required', 'integer', new BelongsToCurrentOrganization(SchoolClass::class)],
            'summary' => ['sometimes', 'boolean'],
            'resources' => ['sometimes', 'boolean'],
            'homework' => ['sometimes', 'boolean'],
            'private_notes' => ['sometimes', 'boolean'],
        ];
    }
}
