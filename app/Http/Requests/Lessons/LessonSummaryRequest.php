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
        foreach (['content', 'private_notes', 'resources', 'homework'] as $field) {
            if (! is_string($this->input($field))) {
                continue;
            }

            $value = trim($this->string($field)->toString());
            $this->merge([$field => $field === 'content' || $value !== '' ? $value : null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:16000'],
            'private_notes' => ['nullable', 'string', 'max:16000'],
            'resources' => ['nullable', 'string', 'max:16000'],
            'homework' => ['nullable', 'string', 'max:16000'],
        ];
    }
}
