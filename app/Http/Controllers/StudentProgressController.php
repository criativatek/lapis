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
use App\Services\Assessment\Progress\BuildStudentFactualAlerts;
use App\Services\Assessment\Progress\BuildStudentInsights;
use App\Services\Assessment\Progress\BuildStudentProgress;
use App\Services\Assessment\Progress\BuildStudentStrengths;
use App\Services\Assessment\Progress\StudentProgressNarrative;
use App\Services\Interventions\Ai\InterventionStrategySuggester;
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
 * unchanged. WHAT CHANGED (Acompanhamento do Aluno, §Pro layer): this
 * controller now DOES carry a deliberate paywall, but only around the
 * INTERPRETIVE layer added on top of the same payload — Estado 360º, trend
 * and regularity readings, analytical alerts, the self-assessment/evidence
 * discrepancy signal, potentialities, "o que mudou" (Pro), evolução após
 * estratégia and "Preparar conversa" all require `advanced_analytics`, gated
 * here on the SERVER before any of it enters the Inertia payload — a Base
 * organization's props simply do not contain the `pro` key. The Base factual
 * alerts and "Pontos fortes" sections stay Base, because they add no
 * interpretation: they are the same kind of arithmetic §43/§44 already
 * protected for `sinceLast` and the class comparison.
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
        protected InterventionStrategySuggester $suggester,
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

        // Base: facts and strengths, always computed — the ATTENTION and the
        // PROGRESS halves of the panel's own philosophy, never only the
        // first (§1, §2).
        $factualAlerts = $this->factualAlerts->for($class, $enrollment, $progress, $period);
        $strengths = $this->strengths->for($class, $enrollment, $progress, $period);
        $allowsAdvancedAnalytics = $this->entitlements->allows('advanced_analytics');
        $previousAlerts = $allowsAdvancedAnalytics
            ? $this->previousPeriodAlerts($class, $enrollment, $progress, $period)
            : null;

        return Inertia::render('student-progress/Show', [
            ...$progress,
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
            'aiPurposeOptions' => InterventionPurpose::options(),
            'aiPurposeSuggestions' => $this->aiPurposeSuggestions($progress),
            // A suggestion is a transient answer to one click, exactly like
            // «Aperfeiçoar redação» — it travels in the session and is gone
            // on the next visit, never stored (§19 of the AI brief).
            'aiSuggestion' => $request->session()->get('aiSuggestion'),
            'aiSuggestionError' => $request->session()->get('aiSuggestionError'),
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

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
