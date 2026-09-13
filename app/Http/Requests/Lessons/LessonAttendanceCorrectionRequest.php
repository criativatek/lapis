<?php

namespace App\Http\Requests\Lessons;

use App\Models\AttendanceStatus;
use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Corrigir UMA linha já consolidada — `PATCH lessons/{lesson}/attendance/{student:ulid}`.
 */
class LessonAttendanceCorrectionRequest extends FormRequest
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
            'status' => ['required', Rule::in(array_map(fn (AttendanceStatus $case): string => $case->value, AttendanceStatus::cases()))],
        ];
    }

    public function status(): AttendanceStatus
    {
        return AttendanceStatus::from($this->validated('status'));
    }
}
