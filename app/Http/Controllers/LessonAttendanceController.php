<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\CorrectLessonAttendance;
use App\Actions\Lessons\RecordLessonAttendance;
use App\Actions\Lessons\SaveLessonAttendanceDraft;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\LessonAttendanceCorrectionRequest;
use App\Http\Requests\Lessons\LessonAttendanceDraftRequest;
use App\Http\Requests\Lessons\LessonAttendanceRecordRequest;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

class LessonAttendanceController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected SaveLessonAttendanceDraft $saveDraft,
        protected RecordLessonAttendance $recordAttendance,
        protected CorrectLessonAttendance $correctAttendance,
    ) {}

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function draft(LessonAttendanceDraftRequest $request, Lesson $lesson): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $this->saveDraft->execute($lesson, $request->absentStudentUlids(), $this->user($request));

        return back()->with('success', 'Faltas guardadas.');
    }

    public function record(LessonAttendanceRecordRequest $request, Lesson $lesson): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $this->recordAttendance->execute($lesson, $this->user($request), $request->absentStudentUlids());

        return back()->with('success', 'Assiduidade registada.');
    }

    public function correct(LessonAttendanceCorrectionRequest $request, Lesson $lesson, Student $student): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $this->correctAttendance->execute($lesson, $student, $request->status(), $this->user($request));

        return back()->with('success', 'Assiduidade corrigida.');
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
