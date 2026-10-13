<?php

namespace App\Http\Controllers;

use App\Models\ActivityEvaluation;
use App\Models\DisciplinarySeverity;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\ParticipationLevel;
use App\Models\RecordFollowupCategory;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ClassCohort;
use App\Services\Audit\AuditLog;
use App\Services\Evidence\Ai\IncidentDescriptionAssistant;
use App\Services\Evidence\EvidenceRecordLabels;
use App\Services\Evidence\Followup\AssessmentPeriodTimeline;
use App\Services\Evidence\Followup\RecordFollowupAlerts;
use App\Services\Evidence\Followup\RecordFollowupNotices;
use App\Services\Evidence\Followup\RecordFollowupOutcome;
use App\Services\Evidence\HomeworkGridRoster;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The teacher's logbook (§14): qualitative entries beside the grades. It never
 * touches the calculation (§14.3) — an entry can only be flagged to appear in a
 * report, never to change a result.
 */
class EvidenceController extends Controller
{
    /** How many of the latest records the class logbook lists. */
    public const int RECENT_RECORDS = 10;

    public function __construct(protected AuditLog $audit, protected RecordFollowupAlerts $followupAlerts) {}

    public function show(Request $request, SchoolClass $class, IncidentDescriptionAssistant $assistant): Response
    {
        Gate::authorize('view', $class);

        // THE CLASS LOGBOOK IS WHERE RECORDS ARE WRITTEN, not where they are
        // consulted (spec 2026-10-12): it shows the few most recent ones to
        // check what was just saved, and the consultation (/records) does the
        // searching. `registo` opens one specific record for editing — the
        // consultation's «Abrir» — and `voltar` brings the teacher back there
        // with filters, page and open case intact.
        $records = EvidenceRecord::query()
            ->forClass($class->id)
            ->with(['enrollment.student.identity', 'domain'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_RECORDS)
            ->get()
            ->map(fn (EvidenceRecord $record) => $this->recordRow($record));

        // A record deleted meanwhile (the teacher removed it from this very
        // page, a stale bookmark) is said so — never a 404 after a delete.
        $editingUlid = $request->query('registo');
        $editing = is_string($editingUlid)
            ? EvidenceRecord::query()->forClass($class->id)->where('ulid', $editingUlid)->with(['enrollment.student.identity', 'domain'])->first()
            : null;

        $backUrl = $request->query('voltar');
        $backUrl = is_string($backUrl) && preg_match('#^/records(?:[/?]|$)#', $backUrl) === 1 ? $backUrl : null;

        return Inertia::render('records/Show', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label, 'subject' => $class->subject->name],
            'activeEnrollmentIds' => $class->activeEnrollments()->pluck('id'),
            // M6: este é o MAPA id→nome para TUDO o que pode aparecer no
            // ecrã — inclui quem já não frequenta esta disciplina, porque um
            // EvidenceRecord existente pode continuar a apontar para ele
            // (registado antes da janela abrir, ou de outro período) e o
            // Vue (`selectableEnrollments`, o dropdown de edição) precisa de
            // resolver o nome de QUALQUER matrícula que um registo referencie,
            // não só de quem frequenta hoje. `all()`, nunca `attending()` —
            // a filtragem de quem pode ser o ALVO DE UM REGISTO NOVO continua
            // a ser feita no Vue com `activeEnrollmentIds` (linha abaixo),
            // que é o único sítio onde «não frequenta» deve mesmo excluir.
            'enrollments' => ClassCohort::for($class)->all()
                ->map(fn ($enrollment) => ['id' => $enrollment->id, 'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)']),
            'domains' => Domain::where('subject_id', $class->subject_id)->orderBy('name')->get(['id', 'name']),
            'kinds' => collect(EvidenceKind::cases())->map(fn (EvidenceKind $kind) => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'group' => $kind->group()->value,
                'group_label' => $kind->group()->label(),
            ]),
            'severities' => collect(DisciplinarySeverity::cases())->map(fn (DisciplinarySeverity $severity) => ['value' => $severity->value, 'label' => $severity->label()]),
            'records' => $records,
            'editingRecord' => $editing === null ? null : $this->recordRow($editing),
            'editingRecordMissing' => is_string($editingUlid) && $editing === null,
            'backUrl' => $backUrl,
            'consultation' => ['year' => $class->academicYear->ulid, 'class' => $class->ulid],
            'periodsNotice' => AssessmentPeriodTimeline::forYear($class->academic_year_id)->isEmpty() ? RecordFollowupNotices::periodsMissing() : null,
            // What «today» means for the form's default date: the application's
            // day, never the UTC one (records/Show.vue, lib/appDate.ts).
            'appTimezone' => (string) config('app.timezone'),
            // «Aperfeiçoar redação» on the ocorrência disciplinar (SUP-U8FMAE).
            // Read on the server before the button is drawn — hiding a control
            // is presentation, not the answer (CLAUDE.md §8.2); the endpoint
            // asks the same question again.
            'ai' => [
                'available' => $assistant->isAvailable(),
                'reason' => $assistant->unavailableReason(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function recordRow(EvidenceRecord $record): array
    {
        return [
            'ulid' => $record->ulid,
            'kind' => $record->kind->value,
            'kind_label' => $record->kind->label(),
            'enrollment_id' => $record->enrollment_id,
            'domain_id' => $record->domain_id,
            'disciplinary_severity' => $record->disciplinary_severity?->value,
            'disciplinary_severity_label' => $record->disciplinary_severity?->label(),
            'homework_status' => $record->homework_status?->value,
            'participation_level' => $record->participation_level?->value,
            'activity_evaluation' => $record->activity_evaluation?->value,
            'activity_include_in_report' => $record->activity_include_in_report,
            'detail_label' => $this->detailLabel($record),
            'description' => $record->description,
            'student' => $record->enrollment === null ? null : (optional($record->enrollment->student->identity)->display_name ?? '(sem identidade)'),
            'domain' => $record->domain?->name,
            'occurred_at' => $record->occurred_at->toIso8601String(),
        ];
    }

    /**
     * A short, type-specific suffix for the compact listing (§10) — e.g.
     * "Não realizado", "Positiva", "Avaliação positiva — Incluir no
     * relatório". Computed once here so the frontend never re-derives it.
     */
    protected function detailLabel(EvidenceRecord $record): ?string
    {
        return EvidenceRecordLabels::detail($record);
    }

    /**
     * A record can target the whole class (empty `enrollment_ids`) or one or
     * more specific students at once — the multi-student grid is a pure
     * authoring shortcut, never a shared record: each selected student gets
     * their own independent row, identical except for `enrollment_id`, each
     * editable and deletable on its own from then on.
     */
    public function store(Request $request, SchoolClass $class, UsageRecorder $usage): RedirectResponse
    {
        Gate::authorize('update', $class);

        $validated = $request->validate(array_merge($this->rulesFor($request->input('kind')), [
            'enrollment_ids' => ['present', 'array'],
            'enrollment_ids.*' => ['integer'],
        ]));

        $this->guardCrossReferences($class, $validated, isNew: true);

        $targets = $validated['enrollment_ids'] === [] ? [null] : $validated['enrollment_ids'];

        $createdEnrollmentIds = [];
        $firstRecord = null;

        $followup = DB::transaction(function () use ($class, $validated, $targets, &$createdEnrollmentIds, &$firstRecord): RecordFollowupOutcome {
            // Serialises saves on this class, so the follow-up's «before»
            // count and the write it compares against belong together.
            SchoolClass::query()->whereKey($class->id)->lockForUpdate()->firstOrFail();

            $snapshot = $this->followupAlerts->before($class, array_map(fn ($enrollmentId): array => [
                'enrollment_id' => $enrollmentId === null ? null : (int) $enrollmentId,
                'category' => RecordFollowupCategory::fromRecord(EvidenceKind::from($validated['kind']), HomeworkStatus::tryFrom((string) ($validated['homework_status'] ?? ''))),
                'date' => Carbon::parse($validated['occurred_at']),
            ], $targets));

            foreach ($targets as $enrollmentId) {
                $record = EvidenceRecord::create([
                    'class_id' => $class->id,
                    'enrollment_id' => $enrollmentId,
                    'domain_id' => $validated['domain_id'] ?? null,
                    'occurred_at' => $validated['occurred_at'],
                    'kind' => $validated['kind'],
                    'disciplinary_severity' => $validated['disciplinary_severity'] ?? null,
                    'homework_status' => $validated['homework_status'] ?? null,
                    'participation_level' => $validated['participation_level'] ?? null,
                    'activity_evaluation' => $validated['activity_evaluation'] ?? null,
                    'activity_include_in_report' => $validated['activity_include_in_report'] ?? null,
                    // The DB column is NOT NULL — an omitted, optional description (§4.4)
                    // is stored as empty, never as a null that would fail the insert.
                    'description' => $validated['description'] ?? '',
                    'created_by' => $this->user()->getKey(),
                ]);
                $firstRecord ??= $record;
                $createdEnrollmentIds[] = $enrollmentId;
            }

            return $this->followupAlerts->after($snapshot);
        });

        // Um evento por pedido, nunca um por aluno: o grelha multi-aluno é um
        // atalho de autoria, não vários registos partilhados — os ids das
        // inscrições (nunca nomes) identificam quem foi visado.
        $this->audit->record(
            'record.created',
            $class,
            $this->user(),
            count($targets) === 1 ? 'Registo criado.' : count($targets).' registos criados.',
            ['class_id' => $class->id, 'kind' => $validated['kind'], 'count' => count($targets), 'enrollment_ids' => $createdEnrollmentIds],
        );

        // Uma ação com N alunos abrangidos, nunca N ações.
        $usage->action(UsageEventType::RecordCreated, $firstRecord, count($targets));

        $message = count($targets) === 1
            ? __('Registo adicionado.')
            : __(':count registos adicionados.', ['count' => count($targets)]);

        $this->flashWithFollowup($message, $followup);

        return back();
    }

    /**
     * The save toast, completed by what the follow-up did — a new alert or an
     * occurrence no period covers. The alert itself is persistent; this only
     * tells the teacher at the moment it happened.
     */
    protected function flashWithFollowup(string $message, RecordFollowupOutcome $followup): void
    {
        $sentences = $followup->toastSentences();

        Inertia::flash('toast', [
            'type' => $followup->hasAlerts() ? 'warning' : ($sentences === [] ? 'success' : 'info'),
            'message' => trim($message.' '.implode(' ', $sentences)),
        ]);
    }

    /**
     * The homework grid of one calendar day: EVERY student who was in the
     * class that day (HomeworkGridRoster), with the marking already saved, if
     * any. Homework batches deliberately have no persisted batch model:
     * class + kind + calendar date is their authoring identity.
     */
    public function homeworkBatch(Request $request, SchoolClass $class, HomeworkGridRoster $roster): JsonResponse
    {
        Gate::authorize('update', $class);

        $validated = $request->validate([
            'occurred_at' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'occurred_at' => $validated['occurred_at'],
            'enrollments' => $roster->for($class, $validated['occurred_at'])['rows'],
        ]);
    }

    /**
     * Saves the rows of the homework grid the teacher changed. A blank status
     * means «Não verificado»: an existing marking of that day is soft-deleted,
     * while a row that never existed remains a no-op.
     *
     * PER STUDENT, NEVER PER GRID:
     * - Each row carries `expected`, the version of the marking the teacher
     *   saw (HomeworkGridRoster::versionOf). With the class locked, a row
     *   whose marking changed in between (edited, created or removed in
     *   another session) is NOT written: it comes back as a conflict with
     *   what is there now. The other rows are saved normally — someone
     *   changing a different student never blocks this one.
     * - A row already in the state asked for counts as saved, whatever its
     *   version: a request repeated after a lost connection, or the same
     *   choice made in two tabs, is never a conflict and never a duplicate.
     * - A new marking needs a student who belonged to the class ON THAT DAY,
     *   even if they left since; correcting an existing marking is never
     *   refused; two homework records of that day are never written through
     *   the grid (they are distinct and are not merged by the date).
     *
     * The outcome per row goes back as flash `homework_save` (saved, unchanged,
     * conflicts) — and the toast never says «guardado» when some row was not.
     * A request without `expected` (an older page) saves as before.
     */
    public function updateHomeworkBatch(Request $request, SchoolClass $class, UsageRecorder $usage, HomeworkGridRoster $roster): RedirectResponse
    {
        Gate::authorize('update', $class);

        $validated = $request->validate([
            'occurred_at' => ['required', 'date_format:Y-m-d'],
            'rows' => ['present', 'array'],
            'rows.*.enrollment_id' => ['required', 'integer', 'distinct'],
            'rows.*.homework_status' => ['nullable', Rule::enum(HomeworkStatus::class)],
            'rows.*.description' => ['nullable', 'string', 'max:1000'],
            'rows.*.expected' => ['nullable', 'string', 'max:64'],
        ]);

        $date = $validated['occurred_at'];
        $enrollmentIds = array_map('intval', array_column($validated['rows'], 'enrollment_id'));
        // Membership against everyone who was ever on the roll — the per-row
        // rules below decide what may be created.
        $this->guardCrossReferences($class, ['enrollment_ids' => $enrollmentIds]);

        $touched = 0;
        $saved = [];
        $unchanged = [];
        $conflicts = [];

        $followup = DB::transaction(function () use ($class, $validated, $date, $enrollmentIds, $roster, &$touched, &$saved, &$unchanged, &$conflicts): RecordFollowupOutcome {
            // There is deliberately no batch table or uniqueness constraint.
            // Locking the existing parent serializes two saves (two tabs
            // creating the same first marking included), where no
            // EvidenceRecord row exists yet for lockForUpdate() to lock.
            SchoolClass::query()->whereKey($class->id)->lockForUpdate()->firstOrFail();

            $existingRecords = $roster->recordsOn($class, $date)
                ->whereIn('enrollment_id', $enrollmentIds)
                ->lockForUpdate()
                ->get()
                ->groupBy('enrollment_id');

            $enrollments = Enrollment::query()->whereIn('id', $enrollmentIds)->get()->keyBy('id');

            // 1. What can never be written through the grid refuses the whole
            //    request before anything is written.
            foreach ($validated['rows'] as $row) {
                $own = $existingRecords->get($row['enrollment_id'], collect());

                if ($own->count() > 1) {
                    throw ValidationException::withMessages([
                        'rows' => __('Um aluno tem mais do que um registo de TPC nesta data. Corrija-os um a um na pesquisa de registos.'),
                    ]);
                }

                if ($own->isEmpty() && ($row['homework_status'] ?? null) !== null) {
                    $eligibility = $roster->eligibilityOn($enrollments->get($row['enrollment_id']), $date);

                    if ($eligibility === HomeworkGridRoster::UNDETERMINED) {
                        throw ValidationException::withMessages([
                            'rows' => __('Não se sabe se um dos alunos pertencia à turma nesta data: a inscrição não está ativa e não tem data de saída. Registe a data de saída na turma para o poder marcar.'),
                        ]);
                    }

                    if ($eligibility !== HomeworkGridRoster::ELIGIBLE) {
                        throw ValidationException::withMessages([
                            'rows' => __('Um dos alunos não pertencia à turma nesta data e não pode receber uma marcação de TPC deste dia.'),
                        ]);
                    }
                }
            }

            // 2. Per student: already as asked, changed meanwhile, or to write.
            $toWrite = [];

            foreach ($validated['rows'] as $row) {
                $enrollmentId = (int) $row['enrollment_id'];
                $own = $existingRecords->get($enrollmentId, collect());
                /** @var EvidenceRecord|null $record */
                $record = $own->first();
                $wantedStatus = $row['homework_status'] ?? null;
                $wantedDescription = $wantedStatus === null ? '' : ($row['description'] ?? '');
                $currentStatus = $record?->homework_status?->value;
                $currentDescription = $record === null ? '' : $record->description;

                if ($currentStatus === $wantedStatus && ($wantedStatus === null || $currentDescription === $wantedDescription)) {
                    $unchanged[] = $enrollmentId;

                    continue;
                }

                $expected = $row['expected'] ?? null;
                $current = $roster->versionOf($own);

                if ($expected !== null && $expected !== $current) {
                    $conflicts[] = [
                        'enrollment_id' => $enrollmentId,
                        'homework_status' => $currentStatus,
                        'description' => $currentDescription,
                        'version' => $current,
                        'change' => match (true) {
                            $record === null => 'removed',
                            $expected === HomeworkGridRoster::VERSION_NONE => 'created',
                            default => 'changed',
                        },
                        'at' => $record?->updated_at?->toIso8601String() ?? $this->lastRemovalOf($class, $enrollmentId, $date),
                    ];

                    continue;
                }

                $toWrite[] = ['row' => $row, 'record' => $record];
            }

            // Only a row actually written as «Não realizado» can add to the
            // follow-up; a conflict or an unchanged row moves nothing.
            $snapshot = $this->followupAlerts->before($class, array_map(fn (array $item): array => [
                'enrollment_id' => (int) $item['row']['enrollment_id'],
                'category' => RecordFollowupCategory::fromRecord(EvidenceKind::Homework, HomeworkStatus::tryFrom((string) ($item['row']['homework_status'] ?? ''))),
                'date' => Carbon::parse($date),
            ], $toWrite));

            foreach ($toWrite as ['row' => $row, 'record' => $record]) {
                $saved[] = (int) $row['enrollment_id'];

                if (($row['homework_status'] ?? null) === null) {
                    if ($record?->delete() === true) {
                        $touched++;
                    }

                    continue;
                }

                $values = [
                    'occurred_at' => $date,
                    'homework_status' => $row['homework_status'],
                    'description' => $row['description'] ?? '',
                ];

                if ($record !== null) {
                    $record->fill($values);

                    if ($record->isDirty()) {
                        $record->save();
                        $touched++;
                    }

                    continue;
                }

                EvidenceRecord::create(array_merge($values, [
                    'class_id' => $class->id,
                    'enrollment_id' => $row['enrollment_id'],
                    'kind' => EvidenceKind::Homework->value,
                    'created_by' => $this->user()->getKey(),
                ]));
                $touched++;
            }

            return $this->followupAlerts->after($snapshot);
        });

        if ($touched > 0) {
            $this->audit->record(
                'record.homework_batch_updated',
                $class,
                $this->user(),
                "Trabalho de casa guardado — {$class->label}.",
                ['class_id' => $class->id, 'occurred_at' => $date, 'count' => $touched],
            );

            // O lote não tem registo próprio: turma + dia é a sua identidade.
            $usage->action(UsageEventType::HomeworkBatchSaved, 'homework:'.$class->getKey().':'.$date, $touched);
        }

        Inertia::flash('homework_save', [
            'occurred_at' => $date,
            'saved' => $saved,
            'unchanged' => $unchanged,
            'conflicts' => $conflicts,
        ]);

        if ($conflicts !== []) {
            $done = count($saved) + count($unchanged);
            $pending = trans_choice(':count aluno alterado noutra sessão desde que abriu a grelha ficou por gravar — reveja-o antes de voltar a guardar.|:count alunos alterados noutra sessão desde que abriu a grelha ficaram por gravar — reveja-os antes de voltar a guardar.', count($conflicts), ['count' => count($conflicts)]);
            $message = $done === 0
                ? __('Nada foi gravado:').' '.$pending
                : __('Gravação parcial:').' '.trans_choice(':count linha gravada|:count linhas gravadas', $done, ['count' => $done]).'; '.$pending;
            $sentences = $followup->toastSentences();

            Inertia::flash('toast', ['type' => 'warning', 'message' => trim($message.' '.implode(' ', $sentences))]);

            return back();
        }

        $this->flashWithFollowup($touched > 0 ? __('Trabalho de casa guardado.') : __('Não havia alterações por guardar.'), $followup);

        return back();
    }

    /**
     * When a student's marking of that day was last removed (put back to «Não
     * verificado» or deleted) — what a conflict says happened.
     */
    private function lastRemovalOf(SchoolClass $class, int $enrollmentId, string $date): ?string
    {
        $removed = EvidenceRecord::onlyTrashed()
            ->forClass($class->id)
            ->where('kind', EvidenceKind::Homework->value)
            ->where('enrollment_id', $enrollmentId)
            ->whereDate('occurred_at', $date)
            ->latest('deleted_at')
            ->first();

        return $removed?->deleted_at?->toIso8601String();
    }

    public function destroyHomeworkBatch(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $validated = $request->validate([
            'occurred_at' => ['required', 'date_format:Y-m-d'],
        ]);

        $deleted = 0;

        DB::transaction(function () use ($class, $validated, &$deleted): void {
            // The same class lock as every save, so a delete never lands
            // between a save's «before» and «after» follow-up counts.
            SchoolClass::query()->whereKey($class->id)->lockForUpdate()->firstOrFail();

            $rows = EvidenceRecord::query()
                ->forClass($class->id)
                ->where('kind', EvidenceKind::Homework->value)
                ->whereDate('occurred_at', $validated['occurred_at'])
                ->whereNotNull('enrollment_id')
                ->lockForUpdate()
                ->get();

            $deleted = $rows->count();
            $rows->each->delete();
        });

        if ($deleted > 0) {
            $this->audit->record(
                'record.homework_batch_deleted',
                $class,
                $this->user(),
                "Trabalho de casa eliminado — {$class->label}.",
                ['class_id' => $class->id, 'occurred_at' => $validated['occurred_at'], 'count' => $deleted],
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Trabalho de casa eliminado.')]);

        return back();
    }

    public function update(Request $request, EvidenceRecord $record): RedirectResponse
    {
        $class = $record->schoolClass;
        Gate::authorize('update', $class);

        $validated = $request->validate($this->rulesFor($request->input('kind')));

        $this->guardCrossReferences($class, $validated);

        $followup = DB::transaction(function () use ($class, $record, $validated): RecordFollowupOutcome {
            SchoolClass::query()->whereKey($class->id)->lockForUpdate()->firstOrFail();

            // An edit can move a record INTO a count — corrected into «Atraso»,
            // to another student, to a date in another period, or to «Não
            // realizado». Only the count it lands in can cross the threshold;
            // re-saving it unchanged leaves that count where it was.
            $snapshot = $this->followupAlerts->before($class, [[
                'enrollment_id' => isset($validated['enrollment_id']) ? (int) $validated['enrollment_id'] : null,
                'category' => RecordFollowupCategory::fromRecord(EvidenceKind::from($validated['kind']), HomeworkStatus::tryFrom((string) ($validated['homework_status'] ?? ''))),
                'date' => Carbon::parse($validated['occurred_at']),
            ]]);

            $record->update([
                'enrollment_id' => $validated['enrollment_id'] ?? null,
                'domain_id' => $validated['domain_id'] ?? null,
                'occurred_at' => $validated['occurred_at'],
                'kind' => $validated['kind'],
                'disciplinary_severity' => $validated['disciplinary_severity'] ?? null,
                'homework_status' => $validated['homework_status'] ?? null,
                'participation_level' => $validated['participation_level'] ?? null,
                'activity_evaluation' => $validated['activity_evaluation'] ?? null,
                'activity_include_in_report' => $validated['activity_include_in_report'] ?? null,
                // The DB column is NOT NULL — an omitted, optional description (§4.4)
                // is stored as empty, never as a null that would fail the insert.
                'description' => $validated['description'] ?? '',
            ]);

            return $this->followupAlerts->after($snapshot);
        });

        $this->audit->record(
            'record.updated',
            $record,
            $this->user(),
            'Registo atualizado.',
            ['class_id' => $class->id, 'evidence_record_id' => $record->id, 'kind' => $record->kind->value],
        );

        $this->flashWithFollowup(__('Registo atualizado.'), $followup);

        return back();
    }

    public function destroy(EvidenceRecord $record): RedirectResponse
    {
        Gate::authorize('update', $record->schoolClass);

        $class = $record->schoolClass;
        $recordId = $record->id;
        $kind = $record->kind->value;

        DB::transaction(function () use ($class, $record): void {
            SchoolClass::query()->whereKey($class->id)->lockForUpdate()->firstOrFail();
            $record->delete();
        });

        $this->audit->record(
            'record.deleted',
            $record,
            $this->user(),
            'Registo eliminado.',
            ['class_id' => $class->id, 'evidence_record_id' => $recordId, 'kind' => $kind],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Registo removido.')]);

        return back();
    }

    /**
     * Shared between store() and update() — every field a kind needs is
     * required for that kind and prohibited for every other one, so a
     * record can never carry a stray value from a kind it isn't.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rulesFor(?string $kind): array
    {
        // EvidenceKind::allowsEmptyDescription() — the same rule a backup
        // restore applies.
        $descriptionOptional = EvidenceKind::tryFrom((string) $kind)?->allowsEmptyDescription() ?? false;

        return [
            'kind' => ['required', Rule::enum(EvidenceKind::class)],
            'description' => [
                Rule::requiredIf(! $descriptionOptional),
                'nullable', 'string', 'max:1000',
            ],
            'occurred_at' => ['required', 'date'],
            'enrollment_id' => ['nullable', 'integer'],
            'domain_id' => ['nullable', 'integer'],
            // Required exactly for a disciplinary occurrence, never set for
            // any other kind — G1 ("Comportamento meritório") is its own
            // EvidenceKind, not a severity of this field. 'nullable' matters
            // here: the real form always sends every kind-specific field,
            // explicit `null` for whichever ones don't belong to the chosen
            // kind — without it, Rule::enum() rejects that null outright,
            // instead of prohibitedIf() being the only thing that runs.
            'disciplinary_severity' => [
                Rule::requiredIf($kind === EvidenceKind::Incident->value),
                Rule::prohibitedIf($kind !== EvidenceKind::Incident->value),
                'nullable', Rule::enum(DisciplinarySeverity::class),
            ],
            'homework_status' => [
                Rule::requiredIf($kind === EvidenceKind::Homework->value),
                Rule::prohibitedIf($kind !== EvidenceKind::Homework->value),
                'nullable', Rule::enum(HomeworkStatus::class),
            ],
            'participation_level' => [
                Rule::requiredIf($kind === EvidenceKind::Participation->value),
                Rule::prohibitedIf($kind !== EvidenceKind::Participation->value),
                'nullable', Rule::enum(ParticipationLevel::class),
            ],
            'activity_evaluation' => [
                Rule::requiredIf($kind === EvidenceKind::Activity->value),
                Rule::prohibitedIf($kind !== EvidenceKind::Activity->value),
                'nullable',
                Rule::enum(ActivityEvaluation::class),
            ],
            'activity_include_in_report' => [
                Rule::requiredIf($kind === EvidenceKind::Activity->value),
                Rule::prohibitedIf($kind !== EvidenceKind::Activity->value),
                'nullable', 'boolean',
            ],
        ];
    }

    /**
     * A targeted entry must point at a student OF THIS class, and a domain OF
     * THIS class's subject — not just anything in the organization. Checks
     * both the single `enrollment_id` (update()'s single-target edit) and the
     * `enrollment_ids` list (store()'s multi-student grid) in one pass.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function guardCrossReferences(SchoolClass $class, array $validated, bool $isNew = false): void
    {
        $enrollmentIds = array_values(array_unique(array_filter(array_merge(
            [$validated['enrollment_id'] ?? null],
            $validated['enrollment_ids'] ?? [],
        ), fn ($id) => $id !== null)));

        // MEMBERSHIP AGAINST EVERYONE WHO WAS EVER ON THIS ROLL: a record made
        // in November belongs to November's students, and correcting it must
        // not fail because one of them has since moved class.
        if ($enrollmentIds !== [] && $class->enrollments()->whereIn('id', $enrollmentIds)->count() !== count($enrollmentIds)) {
            throw ValidationException::withMessages(['enrollment_ids' => __('Aluno inválido para esta turma.')]);
        }

        // A NEW record is about the class as it stands. Only on create, so an
        // edit to something already recorded can never be refused (§3.2, §3.3).
        if ($isNew && $enrollmentIds !== []
            && $class->activeEnrollments()->whereIn('id', $enrollmentIds)->count() !== count($enrollmentIds)) {
            throw ValidationException::withMessages([
                'enrollment_ids' => __('Um aluno que já não integra a turma não pode entrar num registo novo.'),
            ]);
        }
        if (($validated['domain_id'] ?? null) !== null
            && ! Domain::where('subject_id', $class->subject_id)->whereKey($validated['domain_id'])->exists()) {
            throw ValidationException::withMessages(['domain_id' => __('Domínio inválido para esta disciplina.')]);
        }
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
