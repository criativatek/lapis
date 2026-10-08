<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\ClearLessonSummary;
use App\Actions\Lessons\DeleteLesson;
use App\Actions\Lessons\MarkLessonAsTaught;
use App\Actions\Lessons\RecordLessonOutcome;
use App\Actions\Lessons\SaveLessonSummary;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\LessonSummaryContentRequest;
use App\Http\Requests\Lessons\LessonSummaryRequest;
use App\Http\Requests\Lessons\MarkLessonAsTaughtRequest;
use App\Http\Requests\Lessons\RecordLessonOutcomeRequest;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\TeacherAbsenceReason;
use App\Models\User;
use App\Services\Lessons\LessonAttendanceRoster;
use App\Services\Lessons\LessonDayEvents;
use App\Services\Lessons\LessonPreparationContext;
use App\Services\Lessons\ShiftLessonPlanning;
use App\Support\Entitlements\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LessonController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected MarkLessonAsTaught $markLessonAsTaught,
        protected SaveLessonSummary $saveLessonSummary,
        protected ClearLessonSummary $clearLessonSummary,
        protected DeleteLesson $deleteLesson,
        protected LessonAttendanceRoster $attendanceRoster,
        protected RecordLessonOutcome $recordLessonOutcome,
        protected ShiftLessonPlanning $lessonPlanning,
        protected LessonDayEvents $dayEvents,
        protected Entitlements $entitlements,
        protected LessonPreparationContext $lessonContext,
    ) {}

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function show(Request $request, Lesson $lesson): Response
    {
        Gate::authorize('view', $lesson);
        $lesson->load(['schoolClass.subject', 'schoolClass.organization', 'classGroup', 'summary', 'plan']);

        return Inertia::render('lessons/Show', [
            'attendance' => $this->attendanceProp($lesson),
            // Os acontecimentos do Calendário do Ano Letivo que cobrem o dia
            // local desta aula, na mesma turma — nunca com o Calendário
            // trancado (o professor não pode ver o que não pode aceder), e
            // canRead() e não allows(): um plano só de leitura continua a ver
            // os seus próprios acontecimentos aqui, tal como no Calendário.
            'day_events' => $this->entitlements->canRead('calendar')
                ? $this->dayEvents->for($lesson, $this->user($request))
                : [],
            'lesson' => [
                'ulid' => $lesson->ulid,
                'starts_at' => $lesson->starts_at->toIso8601String(),
                'ends_at' => $lesson->ends_at?->toIso8601String(),
                'status' => $lesson->status->value,
                'status_label' => $this->statusLabel($lesson->status),
                'lesson_number' => $lesson->lesson_number,
                // A versão do sumário que este ecrã viu — volta no PUT e no
                // DELETE para o servidor recusar uma gravação sobre texto mais novo.
                'summary_version' => $lesson->summary_version,
                // O resultado real da ocorrência (0.146.0) — NULL enquanto aberta.
                'outcome' => $lesson->outcome?->value,
                'outcome_label' => $lesson->outcome?->label(),
                'outcome_reason_label' => $lesson->outcome_reason?->label(),
                'outcome_note' => $lesson->outcome_note,
                // As mesmas três recusas de RecordLessonOutcome::guard(), para
                // que o botão não prometa o que o servidor recusa.
                'can_record_outcome' => ! $lesson->isClosed()
                    && ! $lesson->attendanceRecorded()
                    && $this->lessonPlanning->closedLessonAfter($lesson) === null,
                'absence_reasons' => array_map(
                    fn (TeacherAbsenceReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
                    TeacherAbsenceReason::cases(),
                ),
                // Planeamento que desceu até aqui e já não coube em nenhuma
                // ocorrência até ao fim do ano (ShiftLessonPlanning).
                'pending_plan' => $lesson->plan?->planned_summary,
                // As duas ações destrutivas desta página decidem-se no
                // servidor e chegam ao ecrã já decididas: esconder um botão é
                // apresentação, e a recusa real vive em DeleteLesson e em
                // ClearLessonSummary, que a repetem por sua conta.
                'can_delete' => ! $lesson->isClosed(),
                'can_clear_summary' => ! $lesson->isTaught()
                    && $lesson->summary !== null
                    && trim($lesson->summary->content) !== '',
                // «8.º F» ou «8.º F · T1» — composto no servidor para que o
                // título, o cabeçalho e o `<Head>` digam todos a mesma coisa.
                'context_label' => $lesson->contextLabel(),
                'class_group_label' => $lesson->classGroup?->label,
                'school_class' => [
                    'ulid' => $lesson->schoolClass->ulid,
                    'label' => $lesson->schoolClass->label,
                    'subject' => $lesson->schoolClass->subject->name,
                ],
                'summary' => $lesson->summary === null ? null : [
                    'content' => $lesson->summary->content,
                    'private_notes' => $lesson->summary->private_notes,
                    'resources' => $lesson->summary->resources,
                    'homework' => $lesson->summary->homework,
                    'reviewed_at' => $lesson->summary->reviewed_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    public function updateSummary(
        LessonSummaryRequest $request,
        Lesson $lesson,
    ): RedirectResponse {
        $this->refuseDuringImpersonation($request);

        $this->saveLessonSummary->execute(
            $lesson,
            [
                'content' => $request->string('content')->toString(),
                'private_notes' => $this->nullableString($request, 'private_notes'),
                'resources' => $this->nullableString($request, 'resources'),
                'homework' => $this->nullableString($request, 'homework'),
            ],
            $this->user($request),
            $request->absentStudentUlids(),
            $request->integer('summary_version'),
        );

        return back()->with('success', 'Sumário guardado.');
    }

    /**
     * Grava SÓ o texto do sumário: a ação recebe a chave `content` e nenhuma
     * outra, para que as notas privadas, os recursos e o TPC fiquem como
     * estavam, byte a byte.
     */
    public function updateSummaryContent(
        LessonSummaryContentRequest $request,
        Lesson $lesson,
    ): RedirectResponse {
        $this->refuseDuringImpersonation($request);

        $this->saveLessonSummary->execute(
            $lesson,
            ['content' => $request->string('content')->toString()],
            $this->user($request),
            null,
            $request->integer('summary_version'),
        );

        return back()->with('success', 'Sumário guardado.');
    }

    /**
     * "Basear no sumário anterior" — a read-only convenience, never a write.
     * (Lê do LessonPreparationContext: a mais recente anterior
     * COM TEXTO, lecionada ou só preparada, e diz qual das duas.)
     * Finds the same class's most recent earlier lesson that already has a
     * summary, and hands its text back so the frontend can copy it into the
     * CURRENT lesson's still-open, not-yet-saved form. Nothing is persisted
     * here, and nothing here links the two lessons afterwards — once copied,
     * it is just text the teacher can edit or overwrite like anything else.
     *
     * DO MESMO GRUPO, E DE MAIS NENHUM. Numa turma desdobrada, T1 e T2 são
     * duas sequências pedagógicas distintas que avançam a ritmos diferentes: o
     * anterior de «8.º F · T1» é a aula anterior de T1, e o anterior da turma
     * inteira é a aula anterior da turma inteira. Oferecer o sumário de T2 a
     * quem está a escrever o de T1 é oferecer matéria que aquele grupo não deu
     * — e como isto pré-preenche um campo que o professor pode gravar sem
     * reler, um engano destes escreve-se sozinho no registo da turma.
     *
     * SEM RECURSO A OUTRO GRUPO quando não há anterior do mesmo. A resposta é
     * a mesma que numa primeira aula do ano — «não há sumário anterior» —, e
     * não o de outro grupo por não haver melhor. Uma sugestão errada custa
     * mais do que sugestão nenhuma.
     *
     * A comparação é com `class_group_id` da PRÓPRIA aula (o instantâneo) e
     * não com o do tempo do horário que a gerou: é o grupo que a aula teve, e
     * é esse que continua verdadeiro depois de o horário ser revisto.
     */
    public function previousSummary(Lesson $lesson): JsonResponse
    {
        Gate::authorize('view', $lesson);

        // O mesmo contexto do painel «Antes desta aula»: a entrada MAIS
        // RECENTE com texto de sumário, lecionada ou apenas preparada — o
        // estado vai na resposta para o ecrã dizer de onde vem o texto.
        $found = $this->lessonContext->latestWithSummary($lesson);

        if ($found === null) {
            return response()->json(null, 204);
        }

        return response()->json([
            'content' => $found['entry']['content'],
            // Continuam a vir: o «Basear» copia-as para o formulário do
            // próprio professor (comportamento de sempre).
            'private_notes' => $found['lesson']->summary?->private_notes,
            'resources' => $found['entry']['resources'],
            'homework' => $found['entry']['homework'],
            'starts_at' => $found['entry']['starts_at'],
            'state' => $found['entry']['state'],
            'state_label' => $found['entry']['state_label'],
        ]);
    }

    /**
     * «Antes desta aula» — as aulas anteriores (lecionadas e preparadas por
     * lecionar) do mesmo público, para quem está a preparar esta. Só leitura;
     * as regras vivem em LessonPreparationContext.
     */
    public function preparationContext(Request $request, Lesson $lesson): JsonResponse
    {
        Gate::authorize('view', $lesson);

        return response()->json($this->lessonContext->for(
            $lesson,
            $request->integer('limit', LessonPreparationContext::DEFAULT_LIMIT),
        ));
    }

    public function markTaught(MarkLessonAsTaughtRequest $request, Lesson $lesson): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $this->markLessonAsTaught->execute($lesson, $this->user($request), $request->absentStudentUlids());

        return back()->with('success', 'Aula marcada como lecionada.');
    }

    /**
     * «Professor ausente» / «Turma em outras atividades letivas» (0.146.0).
     */
    public function recordOutcome(RecordLessonOutcomeRequest $request, Lesson $lesson): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $this->recordLessonOutcome->execute(
            $lesson,
            $request->outcome(),
            $this->user($request),
            $request->reason(),
            $request->note(),
        );

        return back()->with('success', $request->outcome() === LessonOutcome::TeacherAbsent
            ? 'Ausência registada. O planeamento passou para a aula seguinte.'
            : 'Atividade registada. O planeamento passou para a aula seguinte.');
    }

    /**
     * «Limpar sumário» — apaga o TEXTO, mantém a aula. A ação distinta de
     * «Eliminar aula», e é por isso que tem rota própria: as duas confundidas
     * num só botão foi exatamente o que levou professores a apagar a aula para
     * corrigir um sumário.
     */
    public function clearSummary(Request $request, Lesson $lesson): RedirectResponse
    {
        Gate::authorize('update', $lesson);
        $this->refuseDuringImpersonation($request);

        $validated = $request->validate(['summary_version' => ['required', 'integer', 'min:0']]);

        $this->clearLessonSummary->execute($lesson, $this->user($request), (int) $validated['summary_version']);

        return back()->with('success', 'Sumário limpo. A aula foi mantida.');
    }

    /**
     * Eliminar a ocorrência criada por engano. O tempo do horário recorrente
     * que a gerou não é tocado — ver DeleteLesson.
     *
     * Redireciona para a semana da aula, e não `back()`: a página da aula que
     * se acabou de eliminar deixou de existir.
     */
    public function destroy(Request $request, Lesson $lesson): RedirectResponse
    {
        Gate::authorize('delete', $lesson);
        $this->refuseDuringImpersonation($request);

        $week = $lesson->starts_at->copy()->setTimezone('Europe/Lisbon')->startOfWeek()->toDateString();

        $this->deleteLesson->execute($lesson, $this->user($request));

        return redirect()
            ->to('/lessons?week='.$week)
            ->with('success', 'Aula eliminada. O horário da turma não foi alterado.');
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    protected function nullableString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function statusLabel(LessonStatus $status): string
    {
        return match ($status) {
            LessonStatus::Preparation => 'Por preparar',
            LessonStatus::Prepared => 'Preparada',
            LessonStatus::Taught => 'Lecionada',
        };
    }

    /**
     * A prop `attendance` — o instantâneo consolidado quando existe, o roster
     * do dia (com os rascunhos já assinalados) quando ainda não existe. O
     * roster nunca é recalculado depois de consolidado: `RecordLessonAttendance`
     * já o fechou, e reabri-lo aqui divergiria da fotografia que a auditoria
     * guarda.
     *
     * @return array<string, mixed>
     */
    private function attendanceProp(Lesson $lesson): array
    {
        $canEdit = Gate::allows('update', $lesson);

        if ($lesson->attendanceRecorded()) {
            $rows = $lesson->attendances()
                ->with(['student.identity', 'enrollment'])
                ->get()
                ->all();
            usort($rows, function ($a, $b): int {
                $byClassNumber = ($a->enrollment->class_number ?? PHP_INT_MAX) <=> ($b->enrollment->class_number ?? PHP_INT_MAX);

                return $byClassNumber !== 0
                    ? $byClassNumber
                    : (optional($a->student->identity)->display_name ?? '') <=> (optional($b->student->identity)->display_name ?? '');
            });
            $rows = collect($rows);

            return [
                'recorded' => true,
                'recorded_at' => $lesson->attendance_recorded_at?->toIso8601String(),
                'can_edit' => $canEdit,
                'excluded_without_left_on' => 0,
                'students' => $rows->map(fn ($row): array => [
                    'student_ulid' => $row->student->ulid,
                    'enrollment_ulid' => $row->enrollment->ulid,
                    'class_number' => $row->enrollment->class_number,
                    'name' => optional($row->student->identity)->display_name ?? '(sem identidade)',
                    'photo_url' => $row->student->photoUrl(),
                    'status' => $row->status->value,
                ])->values()->all(),
                'counts' => [
                    'present' => $rows->filter(fn ($row) => $row->status->value === 'present')->count(),
                    'absent' => $rows->filter(fn ($row) => $row->status->value === 'absent')->count(),
                ],
                'roster_error' => null,
            ];
        }

        try {
            $roster = $this->attendanceRoster->for($lesson);
        } catch (ValidationException $exception) {
            return [
                'recorded' => false,
                'recorded_at' => null,
                'can_edit' => $canEdit,
                'excluded_without_left_on' => 0,
                'students' => [],
                'counts' => ['present' => 0, 'absent' => 0],
                'roster_error' => collect($exception->errors())->collapse()->first(),
            ];
        }

        $draftAbsentEnrollmentIds = $lesson->attendances()->pluck('enrollment_id')->all();

        return [
            'recorded' => false,
            'recorded_at' => null,
            'can_edit' => $canEdit,
            'excluded_without_left_on' => $roster['excluded_without_left_on'],
            'students' => $roster['students']->map(fn (Enrollment $enrollment): array => [
                'student_ulid' => $enrollment->student->ulid,
                'enrollment_ulid' => $enrollment->ulid,
                'class_number' => $enrollment->class_number,
                'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                'photo_url' => $enrollment->student->photoUrl(),
                'status' => in_array($enrollment->id, $draftAbsentEnrollmentIds, true) ? 'absent' : null,
            ])->values()->all(),
            'counts' => ['present' => 0, 'absent' => 0],
            'roster_error' => null,
        ];
    }
}
