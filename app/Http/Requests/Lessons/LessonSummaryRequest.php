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
            // A versão do sumário que a página viu ao abrir: sem ela, uma
            // página antiga gravava por cima de um texto mais novo sem aviso.
            'summary_version' => ['required', 'integer', 'min:0'],
            'absent' => ['sometimes', 'array', 'max:200'],
            'absent.*' => ['string', 'ulid', 'distinct'],
        ];
    }

    /**
     * @return list<string>|null
     */
    public function absentStudentUlids(): ?array
    {
        if (! $this->has('absent')) {
            return null;
        }

        /** @var list<string> $ulids */
        $ulids = $this->validated('absent', []);

        return $ulids;
    }
}
