<?php

namespace App\Http\Controllers;

use App\Actions\Evidence\ConfirmRecordFollowup;
use App\Actions\Evidence\UndoRecordFollowupConfirmation;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\RecordFollowupCategory;
use App\Models\RecordFollowupConfirmation;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Evidence\EvidenceRecordLabels;
use App\Services\Evidence\Followup\AssessmentPeriodTimeline;
use App\Services\Evidence\Followup\RecordConsultationScope;
use App\Services\Evidence\Followup\RecordFollowupCaseBook;
use App\Services\Evidence\Followup\RecordFollowupNotices;
use App\Services\Evidence\Followup\RecordFollowupPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Registos» — the consultation of the logbook (spec 2026-10-12): filters and a
 * compact summary on entry, never the whole list; three direct accesses to
 * TPC não realizados, atrasos and faltas de material in the period; the
 * alerts still «por tratar»; and the teacher's «Registado no Inovar».
 *
 * Every read is confined to the classes the person teaches
 * (RecordConsultationScope). Creating and editing records stays on the class
 * logbook (EvidenceController); this screen links there and back.
 */
class RecordConsultationController extends Controller
{
    use RefusesDuringImpersonation;

    public const int RESULTS_PER_PAGE = 25;

    public function __construct(
        private readonly RecordFollowupPresenter $presenter,
        private readonly RecordFollowupCaseBook $cases,
    ) {}

    public function index(Request $request): Response
    {
        $scope = RecordConsultationScope::resolve($request, $this->user());
        $searched = $request->query('searched') === '1';
        $view = $this->view($request->query('view'));
        $canConfirm = ! $request->session()->has('impersonator_id');

        // Every expensive prop is a closure: a partial reload (opening a case,
        // refreshing the options) computes only what it asked for.

        return Inertia::render('records/Index', [
            'years' => $scope->years->map(fn ($year) => ['ulid' => $year->ulid, 'label' => $year->label])->values(),
            'filters' => [
                'year' => $scope->year?->ulid,
                'subject' => $scope->subject?->ulid,
                'class' => $scope->class?->ulid,
                'student' => $scope->student?->ulid,
                'kind' => $scope->kind?->value,
                'mode' => $scope->mode,
                'period' => $scope->period?->ulid,
                'from' => $scope->from?->format('Y-m-d'),
                'to' => $scope->to?->format('Y-m-d'),
                'searched' => $searched,
                'view' => $view instanceof RecordFollowupCategory ? $view->value : $view,
            ],
            'options' => fn () => $this->options($scope),
            'context' => $this->context($scope),
            'tiles' => fn () => $this->presenter->tiles($scope),
            'alerts' => fn () => $this->presenter->alertRows($this->cases->pendingAlerts($scope->classIds(), $scope->timeline, $scope->studentId())),
            'results' => fn () => $searched && $view === null && $scope->window !== null ? $this->results($request, $scope) : null,
            'followup' => fn () => $view !== null && $scope->window !== null
                ? [
                    'category' => $view instanceof RecordFollowupCategory ? $view->value : $view,
                    'label' => $view instanceof RecordFollowupCategory ? $view->label() : (string) __('Faltas de presença'),
                    'unit' => $view instanceof RecordFollowupCategory ? 'occurrence' : 'absence',
                    'rows' => $this->paginationPayload($this->presenter->followupRows(
                        $scope,
                        $view,
                        max(1, (int) $request->query('followup_page', '1')),
                        $request->url(),
                        $request->query(),
                    )),
                ]
                : null,
            'openCase' => fn () => $this->openCase($request, $scope, $canConfirm),
            'can' => ['confirm' => $canConfirm],
        ]);
    }

    public function confirm(Request $request, ConfirmRecordFollowup $confirm): RedirectResponse
    {
        // A declaration in the teacher's name — refused to a support session
        // before anything else, an invalid body included.
        $this->refuseDuringImpersonation($request);

        $validated = $request->validate([
            'class' => ['required', 'string'],
            'student' => ['required', 'string'],
            'category' => ['required', Rule::enum(RecordFollowupCategory::class)],
            'period' => ['required', 'string'],
            'occurrences' => ['present', 'array'],
            'occurrences.*.ulid' => ['required', 'string'],
            'occurrences.*.date' => ['required', 'date_format:Y-m-d'],
        ]);

        $class = SchoolClass::where('ulid', $validated['class'])->firstOrFail();
        Gate::authorize('update', $class);

        $enrollment = $class->enrollments()->where('ulid', $validated['student'])->firstOrFail();
        $timeline = AssessmentPeriodTimeline::forYear($class->academic_year_id);
        $period = $timeline->findByUlid($validated['period']);
        abort_if($period === null, 404);

        $created = $confirm($class, $enrollment, RecordFollowupCategory::from($validated['category']), $period, $timeline, array_values($validated['occurrences']), $this->user());

        Inertia::flash('toast', [
            'type' => $created ? 'success' : 'info',
            'message' => $created ? __('Marcado como registado no Inovar.') : __('Este caso já estava marcado como registado no Inovar.'),
        ]);

        return back();
    }

    public function undo(Request $request, RecordFollowupConfirmation $confirmation, UndoRecordFollowupConfirmation $undo): RedirectResponse
    {
        Gate::authorize('update', $confirmation->followupCase->schoolClass);
        $this->refuseDuringImpersonation($request);

        $undo($confirmation, $this->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('A marcação de registado no Inovar foi desfeita.')]);

        return back();
    }

    /**
     * The choices each filter offers — the roll of the selected class is
     * decrypted only here, when the options are actually asked for.
     *
     * @return array<string, mixed>
     */
    private function options(RecordConsultationScope $scope): array
    {
        return [
            'subjects' => $scope->yearClasses->pluck('subject')->unique('id')->sortBy('name')
                ->map(fn ($subject) => ['ulid' => $subject->ulid, 'name' => $subject->name])->values(),
            'classes' => $scope->yearClasses->map(fn (SchoolClass $class) => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'subject_ulid' => $class->subject->ulid,
                'archived' => $class->archived_at !== null,
            ])->values(),
            'students' => $scope->roster->loadMissing('student.identity')->map(fn (Enrollment $enrollment) => [
                'ulid' => $enrollment->ulid,
                'number' => $enrollment->class_number,
                'name' => optional($enrollment->student->identity)->display_name ?? __('(sem identidade)'),
            ])->values(),
            'kinds' => collect(EvidenceKind::cases())->map(fn (EvidenceKind $kind) => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'group_label' => $kind->group()->label(),
            ])->values(),
            'periods' => $scope->timeline->periods()->map(fn ($period) => [
                'ulid' => $period->ulid,
                'label' => $period->label,
                'starts_on' => $period->starts_on->format('Y-m-d'),
                'ends_on' => $period->ends_on->format('Y-m-d'),
                'is_current' => $scope->timeline->periodOn(now())?->is($period) ?? false,
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function context(RecordConsultationScope $scope): array
    {
        $periodsNotice = null;

        if ($scope->year !== null && $scope->timeline->isEmpty()) {
            $periodsNotice = RecordFollowupNotices::periodsMissing();
        } elseif ($scope->mode === RecordConsultationScope::MODE_RANGE && $scope->window !== null
            && ! $scope->timeline->covers($scope->window->from(), $scope->window->to())) {
            $periodsNotice = RecordFollowupNotices::periodsMissing();
        }

        return [
            'has_classes' => $scope->yearClasses->isNotEmpty(),
            'year_label' => $scope->year?->label,
            'scope_label' => $this->scopeLabel($scope),
            'student_label' => $scope->student === null ? null : $this->studentLabel($scope->student),
            'window_label' => $scope->window?->label(),
            'period_is_current' => $scope->periodIsCurrent,
            'needs_window' => $scope->window === null,
            'window_problem' => $scope->invalid['range'] ?? null,
            'periods_notice' => $periodsNotice,
            'overlaps' => array_map(fn (array $pair): string => __(':first e :second', ['first' => $pair[0], 'second' => $pair[1]]), $scope->timeline->overlaps()),
        ];
    }

    private function scopeLabel(RecordConsultationScope $scope): string
    {
        if ($scope->class !== null) {
            return "{$scope->class->label} · {$scope->class->subject->name}";
        }

        if ($scope->subject !== null) {
            return trans_choice(':subject · :count turma|:subject · :count turmas', $scope->classes->count(), ['subject' => $scope->subject->name, 'count' => $scope->classes->count()]);
        }

        return __('Todas as turmas');
    }

    private function category(mixed $value): ?RecordFollowupCategory
    {
        return is_string($value) ? RecordFollowupCategory::tryFrom($value) : null;
    }

    /**
     * A logbook category, or «Faltas de presença» (`absences`).
     */
    private function view(mixed $value): RecordFollowupCategory|string|null
    {
        return $value === RecordFollowupPresenter::ABSENCES ? RecordFollowupPresenter::ABSENCES : $this->category($value);
    }

    private function studentLabel(Enrollment $enrollment): string
    {
        $enrollment->loadMissing('student.identity');
        $name = optional($enrollment->student->identity)->display_name ?? __('(sem identidade)');

        return $enrollment->class_number === null ? $name : "n.º {$enrollment->class_number} · {$name}";
    }

    /**
     * @return array<string, mixed>
     */
    private function results(Request $request, RecordConsultationScope $scope): array
    {
        $query = EvidenceRecord::query()
            ->whereIn('evidence_records.class_id', $scope->classIds())
            ->when($scope->student !== null, fn ($query) => $query->where('evidence_records.enrollment_id', $scope->student->id))
            ->when($scope->kind !== null, fn ($query) => $query->where('evidence_records.kind', $scope->kind->value));

        $scope->window?->applyTo($query);

        $classes = $scope->yearClasses->keyBy('id');

        $paginator = $query
            ->with(['enrollment.student.identity', 'domain'])
            ->orderByDesc('evidence_records.occurred_at')
            ->orderByDesc('evidence_records.id')
            ->paginate(self::RESULTS_PER_PAGE)
            ->withQueryString()
            ->through(function (EvidenceRecord $record) use ($classes): array {
                /** @var SchoolClass $class */
                $class = $classes->get($record->class_id);

                return [
                    'ulid' => $record->ulid,
                    'class_ulid' => $class->ulid,
                    'class_label' => $class->label,
                    'subject' => $class->subject->name,
                    'date' => $record->occurred_at->format('Y-m-d'),
                    'kind' => $record->kind->value,
                    'kind_label' => $record->kind->label(),
                    'detail_label' => EvidenceRecordLabels::detail($record),
                    'severity_label' => $record->disciplinary_severity?->label(),
                    'student' => $record->enrollment === null ? null : [
                        'number' => $record->enrollment->class_number,
                        'name' => optional($record->enrollment->student->identity)->display_name ?? __('(sem identidade)'),
                    ],
                    'description' => $record->description,
                ];
            });

        return $this->paginationPayload($paginator);
    }

    /**
     * The page and the two neighbours' URLs — never the framework's link
     * labels, which have no pt-PT translation in this installation.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array<string, mixed>
     */
    private function paginationPayload(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'prev_url' => $paginator->previousPageUrl(),
            'next_url' => $paginator->nextPageUrl(),
        ];
    }

    /**
     * The case dialog, loaded only when the URL names a case — and, on a
     * partial reload, only when asked for.
     *
     * @return array<string, mixed>|null
     */
    private function openCase(Request $request, RecordConsultationScope $scope, bool $canConfirm): ?array
    {
        $classUlid = $request->query('case_class');
        $studentUlid = $request->query('case_student');
        $category = $this->view($request->query('case_category'));

        if (! is_string($classUlid) || ! is_string($studentUlid) || $category === null || $scope->window === null) {
            return null;
        }

        /** @var SchoolClass|null $class */
        $class = $scope->yearClasses->first(fn (SchoolClass $candidate): bool => $candidate->ulid === $classUlid);
        abort_if($class === null, 404);

        $enrollment = $class->enrollments()->where('ulid', $studentUlid)->firstOrFail();

        if (! $category instanceof RecordFollowupCategory) {
            return $this->presenter->absenceDetail($class, $enrollment, $scope->window);
        }

        return $this->presenter->caseDetail(
            $class,
            $enrollment,
            $category,
            $scope->window,
            $canConfirm && Gate::allows('update', $class),
        );
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
