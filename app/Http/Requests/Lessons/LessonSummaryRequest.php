<?php

namespace App\Http\Requests\Lessons;

use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;

class LessonSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && $this->user()?->can('update', $lesson) === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('content'))) {
            $this->merge(['content' => trim($this->string('content')->toString())]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:16000'],
        ];
    }
}
