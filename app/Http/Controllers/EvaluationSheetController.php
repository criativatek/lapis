<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\CaptureEvaluationSheet;
use App\Support\Assessment\DomainColorPalette;
use App\Support\Entitlements\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pautas de Avaliação — one view, not three. Quantitativo, apreciação
 * qualitativa por domínio e classificação (sugerida vs. decidida) chegam todos
 * ao mesmo tempo; o professor só pode OCULTAR grupos no ecrã, nunca alterar o
 * que o servidor calculou ou decidiu.
 */
class EvaluationSheetController extends Controller
{
    public function __construct(
        protected BuildEvaluationSheet $builder,
        protected CaptureEvaluationSheet $capture,
    ) {}

    public function index(): Response
    {
        $classes = SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
            ->with(['subject', 'academicYear'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'has_profile' => $class->assessment_profile_version_id !== null,
            ]);

        return Inertia::render('evaluation-sheets/Index', ['classes' => $classes]);
    }

    public function show(Request $request, SchoolClass $class, ?string $period = null): Response
    {
        Gate::authorize('view', $class);

        $periods = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')
            ->get();

        $selected = $period !== null
            ? $periods->firstWhere('ulid', $period)
            : $periods->first();

        $sheet = $selected !== null
            ? $this->builder->for($class, $selected)
            : null;

        if ($sheet !== null) {
            // BuildEvaluationSheet's domain rows carry no colour of their own —
            // it is an export-neutral read model and colour is presentation.
            // Resolved through the same seam CaptureEvaluationSheet uses, so
            // the kept photograph can never be painted differently from the
            // screen it was taken of.
            $sheet['domains'] = DomainColorPalette::decorate($sheet['domains']);
        }

        return Inertia::render('evaluation-sheets/Show', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'has_profile' => $class->assessment_profile_version_id !== null,
            ],
            'periods' => $periods->map(fn (AcademicPeriod $academicPeriod) => [
                'ulid' => $academicPeriod->ulid,
                'label' => $academicPeriod->label,
                'kind_label' => $academicPeriod->kind->label(),
                'selected' => $selected !== null && $academicPeriod->id === $selected->id,
            ]),
            'sheet' => $sheet,
            // What the «Guardar esta pauta» form opens with. A SUGGESTION: both
            // fields are editable, the title is built from the period's own
            // configuration (never a hardcoded «semestre»), and the reference
            // date defaults to today clamped into the period, because a date
            // outside it would be refused the moment the teacher pressed save.
            'saveDefaults' => $selected === null ? null : [
                'period_ulid' => $selected->ulid,
                'moment_label' => $this->capture->suggestedLabel($selected),
                'effective_at' => $this->capture->defaultEffectiveDate($selected)->toDateString(),
                'starts_on' => $selected->starts_on->toDateString(),
                'ends_on' => $selected->ends_on->toDateString(),
            ],
            // PRESENTATION ONLY. Hiding the action is not access control — the
            // route itself sits behind `module:inovar_export` and refuses on
            // the server. This just spares a teacher a door that opens onto a
            // 403 (§8.2).
            'canExportToInovar' => app(Entitlements::class)->allows('inovar_export'),
        ]);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
