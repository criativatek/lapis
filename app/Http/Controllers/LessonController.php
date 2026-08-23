<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\SaveLessonPlan;
use App\Actions\Lessons\SaveLessonSummary;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\LessonPlanRequest;
use App\Http\Requests\Lessons\LessonSummaryRequest;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LessonController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected SaveLessonPlan $saveLessonPlan,
        protected SaveLessonSummary $saveLessonSummary,
    ) {}

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function show(Lesson $lesson): Response
    {
        Gate::authorize('view', $lesson);
        $lesson->load(['schoolClass.subject', 'plan', 'summary']);

        return Inertia::render('lessons/Show', [
            'lesson' => [
                'ulid' => $lesson->ulid,
                'starts_at' => $lesson->starts_at->toIso8601String(),
                'ends_at' => $lesson->ends_at?->toIso8601String(),
                'status' => $lesson->status->value,
                'status_label' => $this->statusLabel($lesson->status),
                'school_class' => [
                    'ulid' => $lesson->schoolClass->ulid,
                    'label' => $lesson->schoolClass->label,
                    'subject' => $lesson->schoolClass->subject->name,
                ],
                'plan' => $lesson->plan === null ? null : [
                    'planned_summary' => $lesson->plan->planned_summary,
                ],
                'summary' => $lesson->summary === null ? null : [
                    'content' => $lesson->summary->content,
                    'reviewed_at' => $lesson->summary->reviewed_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    public function updatePlan(
        LessonPlanRequest $request,
        Lesson $lesson,
    ): RedirectResponse {
        $this->refuseDuringImpersonation($request);

        $plannedSummary = $request->validated('planned_summary');

        $this->saveLessonPlan->execute(
            $lesson,
            is_string($plannedSummary) ? $plannedSummary : '',
            LessonStatus::from($request->string('target_status')->toString()),
            $this->user($request),
        );

        return back()->with('success', 'Planeamento guardado.');
    }

    public function updateSummary(
        LessonSummaryRequest $request,
        Lesson $lesson,
    ): RedirectResponse {
        $this->refuseDuringImpersonation($request);

        $this->saveLessonSummary->execute(
            $lesson,
            $request->validated('content'),
            $this->user($request),
        );

        return back()->with('success', 'Sumário guardado.');
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    protected function statusLabel(LessonStatus $status): string
    {
        return match ($status) {
            LessonStatus::Preparation => 'Por preparar',
            LessonStatus::Prepared => 'Preparado',
            LessonStatus::Taught => 'Lecionado',
        };
    }
}
