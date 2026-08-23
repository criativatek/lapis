<?php

namespace App\Http\Requests\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LessonPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && $this->user()?->can('update', $lesson) === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('planned_summary'))) {
            $this->merge(['planned_summary' => trim($this->string('planned_summary')->toString())]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'planned_summary' => ['nullable', 'string', 'max:16000'],
            'target_status' => ['required', Rule::enum(LessonStatus::class)],
        ];
    }
}
