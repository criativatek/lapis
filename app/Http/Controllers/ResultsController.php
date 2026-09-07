<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\Gateway\AiQuotaExceeded;
use App\Services\Assessment\Ai\ResultsAnalyst;
use App\Services\Assessment\BuildClassSynopsis;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\CoverageExplanation;
use App\Services\Assessment\ScaleProposalResolver;
use App\Support\Assessment\DecisionScale;
use App\Support\Entitlements\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        protected ResultsAnalyst $analyst,
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

    public function show(Request $request, SchoolClass $class, ?string $period = null): Response
    {
        Gate::authorize('view', $class);

        $view = $this->periodView($class, $period);

        return Inertia::render('results/Show', [
            ...$view,
            // WHETHER THE BUTTON MAY BE DRAWN, and — separately — whether
            // pressing it could produce anything. A school without the
            // capability is being offered an upgrade; a period with two
            // corrected instruments is a teacher who should come back later.
            // Telling one that it is the other wastes an afternoon (§41).
            'ai' => [
                'available' => $this->analyst->isAvailable(),
                'reason' => $this->analyst->unavailableReason(),
                'has_enough_evidence' => $this->analyst->hasEnoughEvidence($view),
                'minimum_students' => ResultsAnalyst::MINIMUM_STUDENTS_WITH_RESULT,
                // Built here rather than in the browser: the panel posts to a
                // URL it was given, so the period it analyses is the period the
                // server rendered, not one a client assembled.
                'action' => route('results.analyse', array_filter([
                    'class' => $class->ulid,
                    'period' => $this->selectedPeriodUlid($view),
                ])),
            ],
            // A reading is a transient answer to one click, exactly like
            // «Aperfeiçoar redação» and «Analisar com IA» — it travels in the
            // session and is gone on the next visit, never stored.
            'aiAnalysis' => $request->session()->get('resultsAiAnalysis'),
            'aiAnalysisError' => $request->session()->get('resultsAiAnalysisError'),
        ]);
    }

    /**
     * «Analisar a avaliação com IA» — one POST, one reading, nothing written.
     *
     * ON THIS CONTROLLER, NOT A SIBLING, and the reason is the opposite of the
     * one that put `ClassStatisticsAnalysisController` in its own file. That
     * one exists because `ClassStatisticsController`'s docblock promises ONE
     * call to the read model and nothing else, and it should go on being able
     * to promise it. This one reads a payload that is genuinely expensive to
     * assemble — the calculator, the coverage explanations and the whole
     * longitudinal progression — and the reading has to be OF THE SAME payload
     * the teacher was looking at. A sibling controller would have to rebuild
     * it, which means either a second copy of `periodView()` or a public method
     * on this class that exists only for it. Sharing the private method is the
     * smaller of the two, and it makes «the reading is about the screen» true
     * by construction rather than by review.
     *
     * NO WRITE PATH EXISTS FROM HERE. The response is six blocks of text
     * flashed into the session and gone on the next visit. Nothing in this
     * method, and nothing in `ResultsAnalyst`, touches a result, a
     * classification, a weight or a criterion.
     */
    public function analyse(Request $request, SchoolClass $class, ?string $period = null): RedirectResponse
    {
        Gate::authorize('view', $class);

        if (! $this->analyst->isAvailable()) {
            return $this->analysisFailed(self::unavailableMessage($this->analyst->unavailableReason()));
        }

        $view = $this->periodView($class, $period);

        try {
            $analysis = $this->analyst->analyse($class, $view, $this->user());
        } catch (AiUnavailable $exception) {
            // `isAvailable()` was checked above; this only fires in a race no
            // ordinary request hits.
            return $this->analysisFailed(self::unavailableMessage($exception->reason()));
        } catch (AiQuotaExceeded $exception) {
            // A ceiling, not a failure. `publicMessage()` already says whether
            // the exhausted window is the teacher's day, the school's month or
            // the organization's pool.
            return $this->analysisFailed($exception->publicMessage());
        } catch (AiRequestFailed $exception) {
            report($exception);

            // The «too little evidence» refusal arrives here too, as an
            // unusable answer that never reached an engine. Its own sentence,
            // because it is the one failure on this screen the teacher can fix
            // — and the one where the button stays, since registering more
            // elements is exactly what makes the next press work.
            $tooLittleEvidence = ! $this->analyst->hasEnoughEvidence($view);

            return $this->analysisFailed(
                $tooLittleEvidence
                    ? 'Ainda não há resultados suficientes neste período para uma análise. Registe mais elementos e volte a tentar.'
                    : $exception->publicMessage(),
                $tooLittleEvidence || $exception->isRetryable(),
            );
        }

        return back()->with('resultsAiAnalysis', [
            // Which period this reading is OF, so a panel left open while the
            // period selector moves cannot present an old analysis as the new
            // period's.
            'period_ulid' => $this->selectedPeriodUlid($view),
            ...$analysis->toArray(),
        ]);
    }

    /**
     * A controlled failure: a sentence a teacher may read, on a page that still
     * works, with the button still there to press again. Never a 500, never a
     * raw exception, never a status code.
     */
    protected function analysisFailed(string $message, bool $retryable = true): RedirectResponse
    {
        return back()->with('resultsAiAnalysisError', ['message' => $message, 'retryable' => $retryable]);
    }

    /**
     * A reason slug from the gateway, as a sentence a teacher can act on.
     *
     * Three outcomes rather than the gateway's seven — see
     * `HelpAssistantController::unavailableMessage()` for why a teacher is not
     * told which setting is missing.
     */
    public static function unavailableMessage(?string $reason): string
    {
        return match ($reason) {
            'plan' => 'A análise da avaliação com IA não está incluída no plano desta organização.',
            'off' => 'A análise da avaliação com IA não está ativada nesta instalação.',
            default => 'A análise da avaliação com IA não está configurada nesta instalação.',
        };
    }

    /**
     * @param  array<string, mixed>  $view
     */
    protected function selectedPeriodUlid(array $view): ?string
    {
        foreach ($view['periods'] ?? [] as $period) {
            if (is_array($period) && ($period['selected'] ?? false) === true) {
                return is_string($period['ulid'] ?? null) ? $period['ulid'] : null;
            }
        }

        return null;
    }

    /**
     * Everything the period screen shows, as one array.
     *
     * EXTRACTED SO THE READING AND THE SCREEN CANNOT DISAGREE. `show()` renders
     * this; `analyse()` reads it. A figure that reaches the engine is therefore
     * a figure the teacher was looking at, and that property is structural
     * rather than something a reviewer has to check.
     *
     * @return array<string, mixed>
     */
    protected function periodView(SchoolClass $class, ?string $period): array
    {
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

        return [
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
                // The decision is addressed by whose it is, so the cell can be
                // written even before a classification row exists.
                'enrollment_ulid' => (string) $row['enrollment']->ulid,
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
                // What the teacher decided, beside what Lapispro proposed (§6).
                'classification' => $progression[$row['enrollment']->getKey()]['classification'] ?? null,
            ], $results),
        ];
    }

    /**
     * The Quadro Síntese: the whole year, every student, in one table.
     *
     * DUAS LEITURAS, E CADA UMA RESPONDE A UMA PERGUNTA DIFERENTE.
     *
     *   `progression`  o ano visto pelos NÚMEROS: a média ponderada de cada
     *                  unidade, o acumulado do motor, o movimento entre
     *                  unidades. É a leitura que esta página sempre teve e
     *                  continua exatamente como estava.
     *
     *   `synopsis`     o ano visto pelos MOMENTOS: o intercalar e o final de
     *                  cada unidade, a apreciação que vigora em cada um deles,
     *                  os elementos que a sustentam, e a avaliação contínua
     *                  formal. É a leitura longitudinal que faltava.
     *
     * NENHUMA DAS DUAS CALCULA COISA NENHUMA AQUI. Ambas são montadas sobre o
     * mesmo motor, cada uma na sua classe, e recomputar qualquer parte delas
     * neste controlador — ou no browser — seria uma segunda opinião sobre
     * números que já têm uma (§3, §17).
     */
    public function summary(SchoolClass $class, BuildClassSynopsis $synopsis): Response
    {
        Gate::authorize('view', $class);

        $scale = $class->profileVersion?->scale()->with('levels')->first();

        return Inertia::render('results/Summary', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'has_profile' => $class->assessment_profile_version_id !== null,
                'scale_name' => $scale?->name,
            ],
            // The same words and the same colour source the per-period screen
            // uses, so the two cannot disagree about what a class is graded on.
            'decision' => DecisionScale::for($scale)->toPayload(),
            // Presentation only: the route is gated by the same capability, and
            // hiding a link is never what keeps anybody out (§8.2).
            'canExportToInovar' => app(Entitlements::class)->allows('inovar_export'),
            // O Quadro liga ao Relatório do aluno em vez de repetir a análise
            // individual (§14) — e só oferece a porta a quem ela abre. É
            // apresentação: a rota continua atrás do seu próprio módulo.
            'canViewStudentProgress' => app(Entitlements::class)->allows('student_progress'),
            // SE ESTE PROFESSOR PODE CONCLUIR O ANO NUM DOMÍNIO. É apresentação
            // e mais nada: esconder a acção não é o que impede alguém de a fazer
            // — a rota corre a mesma `Gate::authorize('update', $class)` antes
            // de escrever seja o que for (§8.2).
            'canDecideDomains' => Gate::allows('update', $class),
            // A ESCALA INTEIRA, com código e rótulo além da posição: a cor de
            // uma apreciação sai da POSIÇÃO do nível na escala e nunca do número
            // que ele calha ter (§24), e a legenda precisa de a dizer por
            // palavras para que a cor nunca seja a única informação (§25).
            'scaleBands' => $scale === null ? [] : $scale->levels
                ->sortBy('sequence')
                ->map(fn ($level): array => [
                    'code' => (string) $level->code,
                    'label' => (string) $level->label,
                    'sequence' => (int) $level->sequence,
                    'is_negative' => (bool) $level->is_negative,
                ])->values()->all(),
            'progression' => $this->progression->for($class),
            'synopsis' => $synopsis->for($class),
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
