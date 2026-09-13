<?php

namespace App\Http\Requests\Lessons;

use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Registar assiduidade» numa aula já lecionada e ainda sem registo —
 * `POST lessons/{lesson}/attendance`.
 */
class LessonAttendanceRecordRequest extends FormRequest
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
