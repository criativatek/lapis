<?php

namespace App\Http\Requests\Lessons;

use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * O rascunho de faltas — `PUT lessons/{lesson}/attendance/draft`.
 *
 * NUNCA `exists:` — os ulids de aluno são validados contra o roster da própria
 * aula por SaveLessonAttendanceDraft, o único sítio que sabe quem é elegível
 * nesse dia (§ tenancy, «nunca `exists:` em dados de organização»).
 */
class LessonAttendanceDraftRequest extends FormRequest
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
            'absent' => ['present', 'array', 'max:200'],
            'absent.*' => ['string', 'ulid', 'distinct'],
        ];
    }

    /**
     * @return list<string>
     */
    public function absentStudentUlids(): array
    {
        /** @var list<string> $ulids */
        $ulids = $this->validated('absent', []);

        return $ulids;
    }
}
