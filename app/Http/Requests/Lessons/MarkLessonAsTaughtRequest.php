<?php

namespace App\Http\Requests\Lessons;

use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Marcar como lecionada», com uma lista opcional de faltas — o botão
 * individual, que é o único que tem UI de faltas (o lote não tem, ver
 * MarkLessonsAsTaughtInBatch).
 */
class MarkLessonAsTaughtRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && $this->user()?->can('update', $lesson) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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
