<?php

namespace App\Http\Requests\Lessons;

use App\Models\AcademicYear;
use App\Models\LessonSequence;
use App\Models\LessonSequenceItem;
use App\Models\Subject;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LessonSequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $sequence = $this->route('lessonSequence');

        if ($sequence instanceof LessonSequence) {
            return $this->user()?->can('update', $sequence) === true;
        }

        return $this->user()?->can('create', LessonSequence::class) === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('grade_level'))) {
            $gradeLevel = trim($this->string('grade_level')->toString());
            $this->merge(['grade_level' => $gradeLevel !== '' ? $gradeLevel : null]);
        }

        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (['summary', 'private_notes', 'resources', 'homework'] as $field) {
                $value = $item[$field] ?? null;

                if (! is_string($value)) {
                    continue;
                }

                $trimmed = trim($value);
                $items[$index][$field] = $field === 'summary' || $trimmed !== '' ? $trimmed : null;
            }
        }

        $this->merge(['items' => $items]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'subject_id' => ['required', new BelongsToCurrentOrganization(Subject::class)],
            'academic_year_id' => ['required', new BelongsToCurrentOrganization(AcademicYear::class)],
            'grade_level' => ['nullable', 'string', 'max:16'],

            'items' => ['present', 'array'],
            'items.*' => ['array'],
            'items.*.ulid' => ['nullable', 'string', new BelongsToCurrentOrganization(LessonSequenceItem::class, 'ulid')],
            'items.*.summary' => ['required', 'string', 'max:16000'],
            'items.*.private_notes' => ['nullable', 'string', 'max:16000'],
            'items.*.resources' => ['nullable', 'string', 'max:16000'],
            'items.*.homework' => ['nullable', 'string', 'max:16000'],
        ];
    }

    /**
     * An item ulid that belongs to the current organization is still not
     * necessarily THIS sequence's own — without this, a teacher editing their
     * own sequence could smuggle in another one's item ulid and silently
     * adopt (and later delete) a row they do not own.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $sequence = $this->route('lessonSequence');

            if (! $sequence instanceof LessonSequence) {
                return;
            }

            $ownUlids = $sequence->items()->pluck('ulid');
            $items = $this->input('items', []);

            foreach (is_array($items) ? $items : [] as $index => $item) {
                $ulid = is_array($item) ? ($item['ulid'] ?? null) : null;

                if ($ulid !== null && ! $ownUlids->contains($ulid)) {
                    $validator->errors()->add("items.{$index}.ulid", 'O item selecionado não pertence a esta sequência.');
                }
            }
        }];
    }
}
