<?php

namespace App\Http\Controllers;

use App\Domain\Assessment\Bc;
use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\InterventionPurpose;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\Gateway\AiQuotaExceeded;
use App\Services\Assessment\Progress\BuildStudentFactualAlerts;
use App\Services\Assessment\Progress\BuildStudentInsights;
use App\Services\Assessment\Progress\BuildStudentPrintDocument;
use App\Services\Assessment\Progress\BuildStudentProgress;
use App\Services\Assessment\Progress\BuildStudentStrengths;
use App\Services\Assessment\Progress\StudentProgressNarrative;
use App\Services\Interventions\Ai\InterventionStrategySuggester;
use App\Services\Progress\Ai\StudentFollowupSynthesist;
use App\Services\Reporting\Narrative\Phrase;
use App\Support\Entitlements\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Evolução do Aluno: one student's year, read forwards.
 *
 * A VIEW, NOT A DOCUMENT (§3). Relatórios already produces the document — a
 * structured, editable, finalizable, exportable artifact with a snapshot. This
 * is the other thing entirely: a working screen a teacher opens on a Tuesday to
 * see how somebody is doing, changes the reading on, scrolls, and closes. It
 * feeds the report and never replaces it, which is why there is no export here
 * and no «guardar» anywhere on the page (§63).
 *
 * ONE CALL TO THE READ MODEL and nothing else, exactly as Estatística does.
 * Every academic figure was decided by the canonical services before this
 * controller ran; a number computed here, or in the browser, would be a second
 * opinion about a figure that already has one (§1).
 *
 * `student_progress` STAYS A BASE MODULE, and the panel itself is still
 * reachable on Base — reading a class you already have results for is not a
 * separate product from having them, and that precedent from Estatística is
 * unchanged. What Base does NOT get is the reading on top of it: Estado 360º,
 * trend and regularity, analytical alerts, the self-assessment/evidence
 * discrepancy, potentialities, "o que mudou", evolução após estratégia and
 * "Preparar conversa" all require `advanced_analytics`, gated here on the
 * SERVER before any of it enters the Inertia payload — a Base organization's
 * props simply do not contain the `pro` key.
 *
 * «ATENÇÃO», «PONTOS FORTES» AND THE CLASS COMPARISON ARE ON THE PRO SIDE OF
 * THAT LINE, AND THIS IS THE SLICE THAT MOVED THEM. They used to be computed
 * for everybody, on the argument that a count is arithmetic rather than an
 * opinion. The Matriz Mestre draws the line somewhere else, and says so four
 * times: §4 marks «Atenção automática», «Sinais positivos automáticos» and
 * «Pontos fortes identificados automaticamente» for Pro and Institucional
 * only; §3 marks «Leitura automática de forças/dificuldades» and «Comparação
 * contextual com turma» the same way; §5 lists «atenção» and «sinais
 * positivos» among what the PRO síntese adds to the Base ficha; and §24
 * settles the principle — «Base regista e mostra. Pro cruza, interpreta e
 * ajuda a agir.» Gathering results, records, TPC, self-assessments and
 * interventions into one sentence about what deserves attention is crossing
 * sources, whoever does the arithmetic.
 *
 * NOTHING FACTUAL WAS TAKEN AWAY WITH THEM. Every figure those two sections
 * summarise stays exactly where it was on Base: the domain table still shows
 * that Gramática is at 37,5%, the timeline still lists every record and every
 * TPC, the self-assessments and the interventions are untouched. §4's own
 * example is precisely this — Base shows «Gramática — 37,5%», Pro says «a
 * prioridade de consolidação é Gramática». The number is the Base half and it
 * never moved.
 *
 * THE STUDENT IS REACHED THROUGH THEIR ENROLMENT, never through a student id.
 * A result belongs to the (student, class) pair, and a student who has left
 * still has a year — reading them through the enrolment is what keeps that
 * history reachable instead of erasing it with today's roster (§58).
 */
class StudentProgressController extends Controller
{
    public function __construct(
        protected BuildStudentProgress $progress,
        protected StudentProgressNarrative $narrative,
        protected BuildStudentFactualAlerts $factualAlerts,
        protected BuildStudentStrengths $strengths,
        protected BuildStudentInsights $insights,
        protected BuildStudentPrintDocument $printDocument,
        protected InterventionStrategySuggester $suggester,
        protected StudentFollowupSynthesist $synthesist,
        protected Entitlements $entitlements,
    ) {}

    /**
     * Choose a class, then a student (§5).
     *
     * The classes offered are the ones this teacher already sees everywhere
     * else, through the same policy. Nothing here decides access; it asks.
     */
    public function index(): Response
    {
        Gate::authorize('viewAny', SchoolClass::class);

        $classes = SchoolClass::query()
            ->with(['subject', 'academicYear'])
            ->withCount(['enrollments as active_enrollments_count' => fn ($query) => $query->active()])
            ->get()
            ->filter(fn (SchoolClass $class): bool => Gate::allows('view', $class))
            ->sortBy([['academic_year_id', 'desc'], ['label', 'asc']])
            ->values();

        return Inertia::render('student-progress/Index', [
            'classes' => $classes->map(fn (SchoolClass $class): array => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'students' => $class->active_enrollments_count,
                'has_profile' => $class->assessment_profile_version_id !== null,
            ])->all(),
        ]);
    }

    /**
     * The roll of one class, so a teacher can pick a name (§5).
     *
     * EVERYONE WHO WAS EVER IN IT, not only who is in it today. A student who
     * transferred out in March still has a year, and a picker built from the
     * active roster alone would make that year unreachable (§26, §58).
     */
    public function show(SchoolClass $class): Response
    {
        Gate::authorize('view', $class);

        $enrollments = Enrollment::query()
            ->where('class_id', $class->getKey())
            ->with('student.identity')
            ->orderBy('class_number')
            ->get();

        return Inertia::render('student-progress/Class', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'has_profile' => $class->assessment_profile_version_id !== null,
            ],
            'students' => $enrollments->map(fn (Enrollment $enrollment): array => [
                'ulid' => $enrollment->ulid,
                'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                'class_number' => $enrollment->class_number,
                'is_current' => $enrollment->status->isCurrent(),
                'status_label' => $enrollment->status->label(),
                'is_late_entry' => (bool) $enrollment->is_late_entry,
            ])->all(),
        ]);
    }

    /**
     * One student's year.
     *
     * The reading is a QUERY, not a setting: choosing it changes what this page
     * shows and records nothing, exactly as «dados até» does on Estatística.
     */
    public function student(Request $request, SchoolClass $class, Enrollment $enrollment): Response
    {
        Gate::authorize('view', $class);

        // The enrolment has to belong to the class in the URL. Without this a
        // valid ulid from another class would be readable by anyone who can
        // read any class.
        abort_if((int) $enrollment->class_id !== (int) $class->getKey(), 404);

        $validated = $request->validate([
            'leitura' => ['nullable', 'in:continua,periodo'],
        ]);

        $reading = match ($validated['leitura'] ?? null) {
            'continua' => 'accumulated',
            'periodo' => 'period',
            default => null,
        };

        $progress = $this->progress->for($class, $enrollment, $reading);

        $period = ($progress['selectedPeriod']['id'] ?? null) === null
            ? null
            : AcademicPeriod::find((int) $progress['selectedPeriod']['id']);

        $allowsAdvancedAnalytics = $this->entitlements->allows('advanced_analytics');

        // NOT COMPUTED AT ALL WITHOUT THE CAPABILITY, rather than computed and
        // hidden. «Atenção» and «Pontos fortes» are §4's automatic readings and
        // both are Pro; on Base the queries behind them never run and the props
        // carry an empty list, so nothing about what deserves attention reaches
        // the browser for a v-if to hide (§8.2 of CLAUDE.md).
        $factualAlerts = $allowsAdvancedAnalytics
            ? $this->factualAlerts->for($class, $enrollment, $progress, $period)
            : [];
        $strengths = $allowsAdvancedAnalytics
            ? $this->strengths->for($class, $enrollment, $progress, $period)
            : [];
        $previousAlerts = $allowsAdvancedAnalytics
            ? $this->previousPeriodAlerts($class, $enrollment, $progress, $period)
            : null;

        return Inertia::render('student-progress/Show', [
            ...$this->factualPayload($progress, $allowsAdvancedAnalytics),
            // Deterministic, from the figures already in the payload. No AI is
            // involved in this view at all (§38, §82).
            'narrative' => $this->narrative->for($progress),
            'factualAlerts' => $factualAlerts,
            'strengths' => $strengths,
            // GATED ON THE SERVER. A Base organization's props simply do not
            // contain this key — never computed and hidden with v-if, because
            // that would still ship the interpretation to the browser.
            ...($allowsAdvancedAnalytics ? [
                'pro' => $this->insights->for($progress, $factualAlerts, $previousAlerts, $strengths),
            ] : []),
            'ai' => [
                'available' => $this->suggester->isAvailable(),
                'reason' => $this->suggester->unavailableReason(),
            ],
            // THE SYNTHESIS IS A SEPARATE CAPABILITY FROM THE SUGGESTER, and
            // therefore a separate availability block. They sit on the same
            // page and a school may hold either without the other:
            // `ai_followup` reads this student's record, `ai_strategies`
            // proposes measures for it. One key for both would have made
            // «queremos a leitura mas não as propostas» impossible to express.
            'aiSynthesis' => [
                'available' => $this->synthesist->isAvailable(),
                'reason' => $this->synthesist->unavailableReason(),
                'has_enough_evidence' => $this->synthesist->hasEnoughEvidence($progress),
                'action' => route('student-progress.synthesise', [
                    'class' => $class->ulid,
                    'enrollment' => $enrollment->ulid,
                ]),
            ],
            'aiPurposeOptions' => InterventionPurpose::options(),
            'aiPurposeSuggestions' => $this->aiPurposeSuggestions($progress),
            // A suggestion is a transient answer to one click, exactly like
            // «Aperfeiçoar redação» — it travels in the session and is gone
            // on the next visit, never stored (§19 of the AI brief).
            'aiSuggestion' => $request->session()->get('aiSuggestion'),
            'aiSuggestionError' => $request->session()->get('aiSuggestionError'),
            // The same shape, for the same reason: a synthesis is an answer to
            // one click and is gone on the next visit. Nothing about it is
            // stored, attached to the student, or brought back by a refresh.
            'aiSynthesisResult' => $request->session()->get('aiSynthesis'),
            'aiSynthesisError' => $request->session()->get('aiSynthesisError'),
            // Where a teacher goes next, using the flows that already exist —
            // never a second form for the same thing (§64, §65, §66).
            'links' => [
                'records' => route('records.show', ['class' => $class->ulid]),
                // The class's own interventions, filtered to this student.
                // Previously this pointed at the module index with a `turma`
                // query parameter no route ever read, so «Abrir Intervenções»
                // landed on the class picker.
                'interventions' => route('interventions.show', [
                    'class' => $class->ulid,
                    'enrollment_id' => $enrollment->getKey(),
                ]),
                // «Registar intervenção», with this student already chosen. The
                // form is the one that already exists — the link only says who
                // it should open on (§17).
                'newIntervention' => $enrollment->status->isCurrent()
                    ? route('interventions.show', ['class' => $class->ulid, 'aluno' => $enrollment->ulid])
                    : null,
                // Prefilled, not just filtered: the same creation form,
                // taught to accept richer hints — aluno, turma, ano, período
                // — so the teacher does not re-pick what this page already
                // knows.
                'reports' => route('reports.create', [
                    'type' => 'student',
                    'class' => $class->ulid,
                    'enrollment' => $enrollment->ulid,
                    'period' => $progress['selectedPeriod']['id'] ?? null,
                ]),
                'statistics' => route('results.statistics', ['class' => $class->ulid]),
                // AI suggestion action — POSTed to, never GET, so asking for
                // one never lands in browser history.
                'suggestStrategy' => route('student-progress.suggest-strategy', ['class' => $class->ulid, 'enrollment' => $enrollment->ulid]),
            ],
        ]);
    }

    /**
     * A print/PDF-ready document of the same student's year — ONE PRINT
     * INFRASTRUCTURE whose composition adapts to `Entitlements`, never a
     * separate Base/Pro engine and never a second entitlements system (§ print
     * brief). This reuses the exact same collaborators and the exact same
     * assembly `student()` already uses — nothing here is recomputed
     * differently, and no figure gets a second opinion.
     *
     * THE READING IS ALWAYS CANONICAL. A printed document is a snapshot for a
     * meeting, not an interactive screen; the `leitura` toggle exists so a
     * teacher can look at the panel two ways, and neither way makes sense as
     * a persistent artefact somebody carries into a room (§26: it reflects
     * the current state, once, at the moment it is opened).
     *
     * THE ANALYTICAL LAYER IS NOT EVEN COMPUTED when the capability is
     * absent (§9) — `$pro` stays null and never reaches
     * `BuildStudentPrintDocument`, exactly as `student()` already refuses to
     * compute `insights` for a Base organization.
     */
    public function print(SchoolClass $class, Enrollment $enrollment): Response
    {
        Gate::authorize('view', $class);

        // Same tenancy guard as the panel — no shortcuts for the print route.
        abort_if((int) $enrollment->class_id !== (int) $class->getKey(), 404);

        $progress = $this->progress->for($class, $enrollment);

        $period = ($progress['selectedPeriod']['id'] ?? null) === null
            ? null
            : AcademicPeriod::find((int) $progress['selectedPeriod']['id']);

        $allowsAdvancedAnalytics = $this->entitlements->allows('advanced_analytics');

        // The same boundary the panel draws, for the same reason: §5 lists
        // «atenção» and «sinais positivos» among what the PRO síntese adds to
        // the Base ficha, so the Base document does not carry them and does not
        // compute them.
        $factualAlerts = $allowsAdvancedAnalytics
            ? $this->factualAlerts->for($class, $enrollment, $progress, $period)
            : [];
        $strengths = $allowsAdvancedAnalytics
            ? $this->strengths->for($class, $enrollment, $progress, $period)
            : [];

        $pro = null;

        if ($allowsAdvancedAnalytics) {
            $previousAlerts = $this->previousPeriodAlerts($class, $enrollment, $progress, $period);
            $pro = $this->insights->for($progress, $factualAlerts, $previousAlerts, $strengths);
        }

        $document = $this->printDocument->for($progress, $factualAlerts, $strengths, $pro, $allowsAdvancedAnalytics);

        return Inertia::render('student-progress/Print', [
            ...$this->factualPayload($progress, $allowsAdvancedAnalytics),
            'narrative' => $this->narrative->for($progress),
            'factualAlerts' => $factualAlerts,
            'strengths' => $strengths,
            // GATED ON THE SERVER, same as the panel (§15): a Base
            // organization's props simply do not contain this key.
            ...($pro === null ? [] : ['pro' => $pro]),
            'document' => $document,
            'generatedAt' => now()->toDateString(),
        ]);
    }

    /**
     * The read model's payload with the one Pro figure inside it removed when
     * the organization is not entitled to it.
     *
     * `classComparison` — «Comparação contextual com turma» — is marked Pro in
     * §3 AND in §4 of the Matriz Mestre, in two independent tables, and it is
     * the most legible leak of the commercial boundary there was: «72,1%,
     * acima da média da turma (66,4%)» is almost word for word the sentence
     * the Matriz uses to describe what Pro adds. It is built inside
     * `BuildStudentProgress` because `BuildStudentInsights` reads it and the
     * read model must stay one call and one opinion per figure; what changes
     * here is only whether it leaves the server.
     *
     * ONE PLACE, SO THE PANEL AND THE DOCUMENT CANNOT DISAGREE. Both surfaces
     * spread this payload straight into their props, and a condition written
     * twice is a condition that will eventually be written differently. The
     * report's own copy of the same sentence is gated in
     * `StudentSynthesisComposer`, which never sees this payload.
     *
     * @param  array<string, mixed>  $progress
     * @return array<string, mixed>
     */
    protected function factualPayload(array $progress, bool $allowsAdvancedAnalytics): array
    {
        if (! $allowsAdvancedAnalytics) {
            $progress['classComparison'] = null;
        }

        return $progress;
    }

    /**
     * The same factual alerts, one period back — the only extra reading Pro
     * insights needs and Base does not already have, and still just an
     * array BuildStudentFactualAlerts already knows how to build. Resolved
     * here, never inside BuildStudentInsights, which queries nothing (§1 of
     * the Pro insights docblock).
     *
     * @param  array<string, mixed>  $progress
     * @return list<array{key: string, sentence: string, count: int}>|null
     */
    protected function previousPeriodAlerts(SchoolClass $class, Enrollment $enrollment, array $progress, ?AcademicPeriod $period): ?array
    {
        if ($period === null) {
            return null;
        }

        $previous = AcademicPeriod::query()
            ->where('academic_year_id', $period->academic_year_id)
            ->where('sequence', '<', $period->sequence)
            ->orderByDesc('sequence')
            ->first();

        if ($previous === null) {
            return null;
        }

        return $this->factualAlerts->for($class, $enrollment, $progress, $previous);
    }

    /**
     * Three deterministic starting points, each tied to a different factual
     * highlight. A tie or missing value produces null for that purpose; the
     * teacher can still choose any valid domain manually.
     *
     * @param  array<string, mixed>  $progress
     * @return array<string, array<string, mixed>|null>
     */
    protected function aiPurposeSuggestions(array $progress): array
    {
        $highlights = (array) ($progress['domains']['highlights'] ?? []);
        $lowest = $highlights['lowest'] ?? null;
        $rise = $highlights['largest_rise'] ?? null;
        $highest = $highlights['highest'] ?? null;

        return [
            InterventionPurpose::Recovery->value => $this->aiPurposeSuggestion(
                InterventionPurpose::Recovery,
                $lowest,
                is_array($lowest) && ($value = Phrase::percentage($lowest['value'] ?? null)) !== null
                    ? 'Resultado atual mais baixo: '.$value.'.'
                    : null,
            ),
            InterventionPurpose::Consolidation->value => $this->aiPurposeSuggestion(
                InterventionPurpose::Consolidation,
                $rise,
                is_array($rise) && ($value = $this->compactPoints($rise['value'] ?? null)) !== null
                    ? 'Maior evolução recente: '.$value.' p.p., ainda com margem para estabilização.'
                    : null,
            ),
            InterventionPurpose::Improvement->value => $this->aiPurposeSuggestion(
                InterventionPurpose::Improvement,
                $highest,
                is_array($highest) && ($value = Phrase::percentage($highest['value'] ?? null)) !== null
                    ? 'Domínio com desempenho mais elevado: '.$value.'.'
                    : null,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $highlight
     * @return array<string, mixed>|null
     */
    protected function aiPurposeSuggestion(InterventionPurpose $purpose, mixed $highlight, ?string $justification): ?array
    {
        if (! is_array($highlight) || $justification === null) {
            return null;
        }

        return [
            'purpose' => $purpose->value,
            'purpose_label' => $purpose->label(),
            'domain_id' => (int) $highlight['domain_id'],
            'domain' => (string) $highlight['name'],
            'value' => (string) $highlight['value'],
            'justification' => $justification,
        ];
    }

    protected function compactPoints(mixed $value): ?string
    {
        if (! is_string($value) || ! is_numeric($value)) {
            return null;
        }

        $number = Phrase::number(ltrim($value, '+-'));

        if ($number === null) {
            return null;
        }

        return match (Bc::compare(Bc::of($value), '0')) {
            1 => '+'.$number,
            -1 => '−'.$number,
            default => $number,
        };
    }

    /**
     * «Sugestões pedagógicas (IA)». Gated by `ai_assistance` inside
     * the suggester, on the server, independently of `advanced_analytics` —
     * a school can be Pro without an engine configured, and the two
     * questions have two different answers (§41 of the AI brief).
     *
     * IT WRITES NOTHING (§19). The answer is flashed to the session, exactly
     * as «Aperfeiçoar redação» already does it — the panel shows it beside
     * the domain it is about, and the teacher either takes it, through
     * «Adicionar estratégia» / «Adaptar sugestão» and the ordinary creation
     * form, or ignores it. There is no path through this method that creates
     * an Intervention.
     */
    public function suggestStrategy(Request $request, SchoolClass $class, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('view', $class);
        abort_if((int) $enrollment->class_id !== (int) $class->getKey(), 404);

        $validated = $request->validate([
            'domain_id' => ['required', 'integer'],
            'purpose' => ['required', Rule::enum(InterventionPurpose::class)],
            'teacher_objective' => ['nullable', 'string', 'max:1000'],
        ]);

        $progress = $this->progress->for($class, $enrollment);
        $domain = collect((array) ($progress['domains']['rows'] ?? []))
            ->firstWhere('domain_id', (int) $validated['domain_id']);

        if (! is_array($domain)) {
            throw ValidationException::withMessages([
                'domain_id' => __('Domínio inválido para este aluno.'),
            ]);
        }

        $purpose = InterventionPurpose::from((string) $validated['purpose']);
        $teacherObjective = isset($validated['teacher_objective'])
            ? trim((string) $validated['teacher_objective'])
            : null;
        $teacherObjective = $teacherObjective === '' ? null : $teacherObjective;

        $this->guardAiContentHasNoDirectIdentifiers($teacherObjective, $enrollment);

        if (! $this->suggester->isAvailable()) {
            return $this->suggestionFailed($this->suggester->unavailableReason() === 'plan'
                ? 'O apoio de IA não está incluído no plano desta organização.'
                : 'O apoio de IA não está configurado nesta instalação.');
        }

        $existingStrategies = array_values(array_filter(array_map(
            function (array $row) use ($enrollment): ?array {
                $name = is_string($row['title'] ?? null) ? trim($row['title']) : null;
                $objective = is_string($row['objective'] ?? null) ? trim($row['objective']) : null;

                if ($this->containsDirectIdentifier($name, $enrollment)
                    || $this->containsDirectIdentifier($objective, $enrollment)) {
                    return null;
                }

                return ['name' => $name, 'objective' => $objective];
            },
            array_values(array_filter(
                (array) ($progress['interventions']['rows'] ?? []),
                fn (array $row): bool => ($row['domain'] ?? null) === $domain['name'],
            )),
        )));

        try {
            $suggestions = $this->suggester->suggest(
                domainName: (string) $domain['name'],
                purpose: $purpose,
                factualPattern: $this->factualPattern($domain, $purpose),
                existingStrategies: $existingStrategies,
                teacherObjective: $teacherObjective,
                author: $this->user(),
            );
        } catch (AiUnavailable) {
            // Not `$exception->publicMessage()`: that message is worded for
            // «Aperfeiçoar redação» specifically (AiUnavailable is shared
            // infrastructure, and this is the one message that names a
            // different feature). isAvailable() was already checked above,
            // so this only fires in a race no normal request hits — and even
            // then the sentence stays accurate for THIS feature.
            return $this->suggestionFailed('O apoio de IA não está configurado nesta instalação.');
        } catch (AiQuotaExceeded $exception) {
            // A ceiling, not a failure. It reaches this controller because the
            // ceiling now lives in `AiGateway` rather than in the route
            // throttle that used to answer 429 here — and `publicMessage()`
            // already says which window ran out and when it renews.
            return $this->suggestionFailed($exception->publicMessage());
        } catch (AiRequestFailed $exception) {
            report($exception);

            return $this->suggestionFailed($exception->publicMessage());
        }

        return back()->with('aiSuggestion', [
            'domain_id' => (int) $domain['domain_id'],
            'domain' => $domain['name'],
            'purpose' => $purpose->value,
            'purpose_label' => $purpose->label(),
            'enrollment_ulid' => $enrollment->ulid,
            'suggestions' => array_map(fn ($suggestion) => $suggestion->toArray(), $suggestions),
        ]);
    }

    /**
     * @param  array<string, mixed>  $domain
     */
    protected function factualPattern(array $domain, InterventionPurpose $purpose): string
    {
        if ($purpose === InterventionPurpose::Consolidation) {
            $evolution = $domain['evolution'] ?? null;

            if (! is_array($evolution) || ($points = $this->compactPoints($evolution['points'] ?? null)) === null) {
                return 'Ainda sem evolução recente registada neste domínio.';
            }

            return 'Evolução recente no domínio: '.$points.' p.p.';
        }

        $value = Phrase::percentage($domain['accumulated_average'] ?? null);

        return $value === null
            ? 'Ainda sem resultado atual apurado neste domínio.'
            : 'Resultado atual no domínio: '.$value.'.';
    }

    protected function guardAiContentHasNoDirectIdentifiers(?string $text, Enrollment $enrollment): void
    {
        if (! $this->containsDirectIdentifier($text, $enrollment)) {
            return;
        }

        throw ValidationException::withMessages([
            'teacher_objective' => __('Não inclua nomes, emails, números de processo ou outros identificadores diretos no objetivo enviado à IA.'),
        ]);
    }

    protected function containsDirectIdentifier(?string $text, Enrollment $enrollment): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        $enrollment->loadMissing('student.identity');
        $identifiers = array_filter([
            $enrollment->student->identity?->display_name,
            $enrollment->student->processNumber(),
            $enrollment->ulid,
            $enrollment->student->ulid,
        ], fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        foreach ($identifiers as $identifier) {
            if (mb_stripos($text, $identifier) !== false) {
                return true;
            }
        }

        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $text) === 1;
    }

    protected function suggestionFailed(string $message): RedirectResponse
    {
        return back()->with('aiSuggestionError', ['message' => $message]);
    }

    /**
     * «Síntese de acompanhamento (IA)» — one POST, one reading, nothing written.
     *
     * IT REBUILDS THE SAME PAYLOAD THE PANEL IS SHOWING, through the same three
     * collaborators `student()` uses and in the same order. That is what makes
     * the synthesis a reading OF the screen rather than a second opinion about
     * it: the facts handed to the engine are the facts printed above the place
     * the answer appears.
     *
     * THE FACTS GO WITH IT, DELIBERATELY. `factualAlerts` and `strengths` are
     * composed by this application from counts and states — no teacher's free
     * text is in either — and sending them is what lets the model work from
     * what the system actually established instead of inferring it from
     * numbers. It is also what makes the panel's own «facto vs interpretação»
     * split legible: the same sentences appear above, unlabelled as AI, because
     * they are not.
     *
     * IT WRITES NOTHING (§15). The answer is flashed to the session, exactly as
     * «Sugestões de estratégia» and «Aperfeiçoar redação» already do. There is
     * no path through this method that changes a result, a classification, an
     * intervention, a record or the student.
     */
    public function synthesise(Request $request, SchoolClass $class, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('view', $class);
        abort_if((int) $enrollment->class_id !== (int) $class->getKey(), 404);

        if (! $this->synthesist->isAvailable()) {
            return $this->synthesisFailed(self::synthesisUnavailableMessage($this->synthesist->unavailableReason()));
        }

        $progress = $this->progress->for($class, $enrollment);

        $period = ($progress['selectedPeriod']['id'] ?? null) === null
            ? null
            : AcademicPeriod::find((int) $progress['selectedPeriod']['id']);

        $factualAlerts = $this->factualAlerts->for($class, $enrollment, $progress, $period);
        $strengths = $this->strengths->for($class, $enrollment, $progress, $period);

        try {
            $synthesis = $this->synthesist->synthesise(
                $class,
                $enrollment,
                $progress,
                $factualAlerts,
                $strengths,
                $this->user(),
            );
        } catch (AiUnavailable $exception) {
            // `isAvailable()` was checked above; this only fires in a race no
            // ordinary request hits.
            return $this->synthesisFailed(self::synthesisUnavailableMessage($exception->reason()));
        } catch (AiQuotaExceeded $exception) {
            // A ceiling, not a failure. `publicMessage()` already says whether
            // the exhausted window is the teacher's day, the school's month or
            // the organization's pool.
            return $this->synthesisFailed($exception->publicMessage());
        } catch (AiRequestFailed $exception) {
            report($exception);

            // The «too little evidence» refusal arrives here too, as an
            // unusable answer that never reached an engine. Its own sentence,
            // because it is the one failure here the teacher can fix.
            return $this->synthesisFailed(
                $this->synthesist->hasEnoughEvidence($progress)
                    ? $exception->publicMessage()
                    : 'Ainda não há resultados, registos ou intervenções suficientes para uma síntese deste aluno.',
            );
        }

        return back()->with('aiSynthesis', [
            'enrollment_ulid' => $enrollment->ulid,
            // Which period the panel was showing, so a synthesis left on screen
            // while the reading toggle moves cannot be read as the new one's.
            'period_id' => $progress['selectedPeriod']['id'] ?? null,
            ...$synthesis->toArray(),
        ]);
    }

    protected function synthesisFailed(string $message): RedirectResponse
    {
        return back()->with('aiSynthesisError', ['message' => $message]);
    }

    /**
     * A reason slug from the gateway, as a sentence a teacher can act on.
     *
     * Three outcomes rather than the gateway's seven — see
     * `HelpAssistantController::unavailableMessage()` for why a teacher is not
     * told which setting is missing.
     */
    public static function synthesisUnavailableMessage(?string $reason): string
    {
        return match ($reason) {
            'plan' => 'A síntese de acompanhamento com IA não está incluída no plano desta organização.',
            'off' => 'A síntese de acompanhamento com IA não está ativada nesta instalação.',
            default => 'A síntese de acompanhamento com IA não está configurada nesta instalação.',
        };
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
