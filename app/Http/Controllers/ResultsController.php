<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\CoverageExplanation;
use App\Services\Assessment\ScaleProposalResolver;
use App\Support\Assessment\DecisionScale;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ResultsController extends Controller
{
    public function __construct(
        protected ClassResultsCalculator $calculator,
        protected ScaleProposalResolver $proposals,
        protected CoverageExplanation $coverage,
        protected BuildResultsProgression $progression,
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

        return Inertia::render('results/Index', ['classes' => $classes]);
    }

    public function show(SchoolClass $class, ?string $period = null): Response
    {
        Gate::authorize('view', $class);

        $periods = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')->get();

        $selected = $period !== null
            ? $periods->firstWhere('ulid', $period)
            : $periods->first();

        $results = $selected !== null
            ? $this->calculator->forPeriod($class, $selected)
            : [];

        // Domain names for the columns — the stable domains this class's version uses.
        $domainNames = Domain::whereIn(
            'id',
            collect($results)->flatMap(fn ($row) => collect($row['outcome']->domains)->pluck('domainId'))->unique()->values(),
        )->pluck('name', 'id');

        // The scale the profile version in force actually uses — with its levels,
        // so resolving each row's proposal costs no further query. The rounding
        // travels with it: a numeric scale's proposal obeys the same rule the
        // teacher configured for the result.
        $version = $class->profileVersion;
        $scale = $version?->scale()->with('levels')->first();
        $roundingMode = $version->rounding_mode ?? 'half_up';
        $roundingScale = $version->rounding_scale ?? 0;

        // Why each ⚠ was raised, resolved once for the whole page. The engine
        // already recorded the state; this only names the instrument behind it so
        // the teacher reads "Teste de Compreensão Leitora · 15/10/2026" instead of
        // guessing which elements the note refers to.
        $coverageNotes = $this->coverage->forResults($results);

        // The longitudinal read, once, for the three things this screen cannot
        // work out on its own: what the student said about themselves, what the
        // teacher decided, and whether this period is better than the last.
        //
        // Keyed by enrolment so the rows below can pick their own entry without
        // searching, and asked for once rather than per student (§16).
        $progression = $selected === null ? [] : $this->progressionFor($class, $selected);

        // «Média Ponderada» is this period's own evidence. The screen shows
        // exactly that — forPeriod, not forAccumulated — so it is never labelled
        // «Acumulada» for convenience (§4).
        $isFirstPeriod = $selected !== null && $periods->first()?->id === $selected->id;

        return Inertia::render('results/Show', [
            'weightedAverageLabel' => 'Média Ponderada',
            'isFirstPeriod' => $isFirstPeriod,
            // The profile's own bands, for the canonical colour resolver. The
            // screen never decides what colour a level is (§7).
            'scaleBands' => $scale === null ? [] : $scale->levels
                ->map(fn ($level): array => [
                    'label' => (string) $level->label,
                    'sequence' => (int) $level->sequence,
                    'is_negative' => (bool) $level->is_negative,
                ])->values()->all(),
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'has_profile' => $class->assessment_profile_version_id !== null,
                'scale_name' => $scale?->name,
            ],
            // This is where the teacher decides, because this is where they can
            // see the student: the domains, the average, the proposal and what
            // the student said about themselves, all at once. The decision is
            // written through the same service Classificações uses — the same
            // row, the same validation, the same trail.
            'decision' => DecisionScale::for($scale)->toPayload(),
            'periods' => $periods->map(fn (AcademicPeriod $academicPeriod) => [
                'ulid' => $academicPeriod->ulid,
                'label' => $academicPeriod->label,
                'selected' => $selected !== null && $academicPeriod->id === $selected->id,
            ]),
            'domains' => collect($results)
                ->flatMap(fn ($row) => collect($row['outcome']->domains)->pluck('domainId'))
                ->unique()->values()
                ->map(fn (int $id) => ['id' => $id, 'name' => $domainNames[$id] ?? '—']),
            'rows' => array_map(fn (array $row) => [
                'name' => optional($row['enrollment']->student->identity)->display_name ?? '(sem identidade)',
                'photo_url' => $row['enrollment']->student->photoUrl(),
                'class_number' => $row['enrollment']->class_number,
                'overall' => $row['outcome']->normalizedValue,
                // The result stays a percentage; the proposal is that result read
                // on the profile's own scale — a 4, a 16, an 80%, a "Bom".
                'proposal' => $this->proposals->resolve(
                    $scale,
                    $row['outcome']->scaleLevelId,
                    $row['outcome']->normalizedValue,
                    $row['outcome']->proposedValue,
                    $roundingMode,
                    $roundingScale,
                )->toPayload(),
                'has_value' => $row['outcome']->hasValue(),
                'coverage_warning' => $row['outcome']->coverageWarning,
                'coverage' => $coverageNotes[$row['enrollment']->getKey()]['overall'] ?? CoverageExplanation::none(),
                'domains' => array_map(fn ($domain) => [
                    'domain_id' => $domain->domainId,
                    'value' => $domain->normalizedValue,
                    'warning' => $domain->coverageWarning,
                    'coverage' => $coverageNotes[$row['enrollment']->getKey()]['domains'][$domain->domainId] ?? CoverageExplanation::none(),
                    // Trend, never performance: whether this domain moved since
                    // the period before, judged on standalone figures (§8).
                    'evolution' => $progression[$row['enrollment']->getKey()]['domains'][$domain->domainId] ?? null,
                ], $row['outcome']->domains),
                'evolution' => $progression[$row['enrollment']->getKey()]['evolution'] ?? null,
                'accumulated' => $progression[$row['enrollment']->getKey()]['accumulated'] ?? null,
                // The student's own overall judgement — the answer to the global
                // question, never the average of the per-domain ones (§5).
                'self_assessment' => $progression[$row['enrollment']->getKey()]['self_assessment'] ?? null,
                // What the teacher decided, beside what LÁPIS proposed (§6).
                'classification' => $progression[$row['enrollment']->getKey()]['classification'] ?? null,
            ], $results),
        ]);
    }

    /**
     * The parts of the longitudinal read this period's screen needs, keyed by
     * enrolment.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function progressionFor(SchoolClass $class, AcademicPeriod $selected): array
    {
        $byEnrollment = [];

        foreach ($this->progression->for($class)['students'] as $student) {
            foreach ($student['periods'] as $period) {
                if ($period['period_id'] !== $selected->id) {
                    continue;
                }

                $domains = [];

                foreach ($period['domains'] as $domain) {
                    $domains[$domain['domain_id']] = $domain['evolution'];
                }

                $byEnrollment[$student['enrollment_id']] = [
                    'evolution' => $period['evolution'],
                    'accumulated' => $period['accumulated_average'],
                    'self_assessment' => $period['self_assessment'],
                    'classification' => $period['classification'],
                    'domains' => $domains,
                ];
            }
        }

        return $byEnrollment;
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
