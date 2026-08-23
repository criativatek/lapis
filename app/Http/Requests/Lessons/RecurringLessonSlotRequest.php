<?php

namespace App\Http\Requests\Lessons;

use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

class RecurringLessonSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $schoolClass = SchoolClass::query()->find($this->integer('class_id'));

        if ($user === null || $schoolClass === null) {
            return false;
        }

        $recurringLessonSlot = $this->route('recurringLessonSlot');

        if ($recurringLessonSlot instanceof RecurringLessonSlot) {
            return $recurringLessonSlot->class_id === $schoolClass->id
                && $user->can('update', $recurringLessonSlot);
        }

        return $user->can('create', [RecurringLessonSlot::class, $schoolClass]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $endsOnRules = ['nullable', 'date_format:Y-m-d'];

        if ($this->filled('starts_on')) {
            $endsOnRules[] = 'after_or_equal:starts_on';
        }

        return [
            'class_id' => ['required', 'integer', new BelongsToCurrentOrganization(SchoolClass::class)],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => $endsOnRules,
        ];
    }
}
