<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonOutcomeRectification;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use App\Support\Lessons\NotHeldOptions;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Aulas fechadas» de uma turma, para a direção da instituição (0.194.0): a porta por onde anula um
 * «Lecionada» de uma turma que não leciona. CORRIGIR ≠ CONSULTAR: sem sumário, sem faltas, sem causa
 * detalhada nem dado reservado — só o que a semana já mostra a qualquer colega. A página da aula
 * continua 403 para quem não leciona.
 */
class LessonAnnulmentController extends Controller
{
    public function index(SchoolClass $class): Response
    {
        Gate::authorize('reviewRectifications', $class);

        $lessons = Lesson::query()
            ->where('class_id', $class->id)
            ->where(fn ($query) => $query->whereNotNull('outcome')->orWhere('status', LessonStatus::Taught))
            ->with(['classGroup', 'outcomeRecorder'])
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->limit(200)
            ->get();

        $annulments = LessonOutcomeRectification::query()
            ->where('kind', LessonOutcomeRectification::KIND_ANNULMENT)
            ->whereIn('lesson_id', Lesson::query()->where('class_id', $class->id)->select('id'))
            ->with(['rectifier', 'lesson'])
            ->orderByDesc('rectified_at')->orderByDesc('id')
            ->get();

        return Inertia::render('classifications/rectifications/Lessons', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject?->name,
                'academic_year' => $class->academicYear->label,
            ],
            'lessons' => $lessons->map(function (Lesson $lesson): array {
                // Uma aula fechada antes da 0.146.0 tem `status = taught` e resultado nulo: lê-se «Lecionada».
                $outcome = $lesson->outcome ?? LessonOutcome::Taught;

                return [
                    'ulid' => $lesson->ulid,
                    'date' => $lesson->starts_at->setTimezone('Europe/Lisbon')->format('d/m/Y'),
                    'time' => $lesson->starts_at->setTimezone('Europe/Lisbon')->format('H:i'),
                    'context_label' => $lesson->contextLabel(),
                    'outcome' => $outcome->value,
                    'outcome_label' => $outcome->label(),
                    'lesson_number' => $lesson->lesson_number === null ? null : (int) $lesson->lesson_number,
                    'closed_by' => $lesson->outcomeRecorder?->name,
                    // A mesma condição da policy, menos a pessoa: a policy decide no PATCH.
                    'can_annul' => (bool) config('lapis.lesson_not_held_enabled', false) && $lesson->isTaught() && $lesson->lesson_unit_key === null,
                ];
            })->values()->all(),
            'annulments' => $annulments->map(fn (LessonOutcomeRectification $rectification): array => [
                'ulid' => $rectification->ulid,
                'at' => $rectification->rectified_at->toIso8601String(),
                'by' => $rectification->rectifier?->name,
                'lesson_date' => $rectification->lesson?->starts_at->setTimezone('Europe/Lisbon')->format('d/m/Y'),
                'lesson_number' => $rectification->previous['lesson_number'] ?? null,
                'motive' => $rectification->motive,
            ])->values()->all(),
            'notHeldOptions' => NotHeldOptions::forPage(),
        ]);
    }
}
