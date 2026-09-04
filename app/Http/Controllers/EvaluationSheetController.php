<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Support\Assessment\DomainColorPalette;
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
            // Fetched once, keyed by id, so a class with many domains costs one
            // extra query rather than one per row.
            $configuredColors = Domain::query()
                ->whereIn('id', array_column($sheet['domains'], 'domain_id'))
                ->pluck('color', 'id');

            $sheet['domains'] = array_map(
                fn (array $domain): array => [
                    ...$domain,
                    'color' => DomainColorPalette::for($configuredColors[$domain['domain_id']] ?? null, $domain['sequence']),
                ],
                $sheet['domains'],
            );
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
        ]);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
