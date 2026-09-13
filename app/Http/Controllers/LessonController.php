<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\ClearLessonSummary;
use App\Actions\Lessons\DeleteLesson;
use App\Actions\Lessons\MarkLessonAsTaught;
use App\Actions\Lessons\SaveLessonSummary;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\LessonSummaryRequest;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;
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
    ) {}

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function show(Lesson $lesson): Response
    {
        Gate::authorize('view', $lesson);
        $lesson->load(['schoolClass.subject', 'classGroup', 'summary']);

        return Inertia::render('lessons/Show', [
            'lesson' => [
                'ulid' => $lesson->ulid,
                'starts_at' => $lesson->starts_at->toIso8601String(),
                'ends_at' => $lesson->ends_at?->toIso8601String(),
                'status' => $lesson->status->value,
                'status_label' => $this->statusLabel($lesson->status),
                'lesson_number' => $lesson->lesson_number,
                // As duas ações destrutivas desta página decidem-se no
                // servidor e chegam ao ecrã já decididas: esconder um botão é
                // apresentação, e a recusa real vive em DeleteLesson e em
                // ClearLessonSummary, que a repetem por sua conta.
                'can_delete' => $lesson->status !== LessonStatus::Taught,
                'can_clear_summary' => $lesson->status !== LessonStatus::Taught
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
        );

        return back()->with('success', 'Sumário guardado.');
    }

    /**
     * "Basear no sumário anterior" — a read-only convenience, never a write.
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

        $previous = Lesson::query()
            ->where('class_id', $lesson->class_id)
            ->where(fn ($query) => $lesson->class_group_id === null
                ? $query->whereNull('class_group_id')
                : $query->where('class_group_id', $lesson->class_group_id))
            ->where('starts_at', '<', $lesson->starts_at)
            ->whereHas('summary')
            ->with('summary')
            ->orderByDesc('starts_at')
            ->first();

        if ($previous === null || $previous->summary === null) {
            return response()->json(null, 204);
        }

        return response()->json([
            'content' => $previous->summary->content,
            'private_notes' => $previous->summary->private_notes,
            'resources' => $previous->summary->resources,
            'homework' => $previous->summary->homework,
        ]);
    }

    public function markTaught(Request $request, Lesson $lesson): RedirectResponse
    {
        Gate::authorize('update', $lesson);
        $this->refuseDuringImpersonation($request);

        $this->markLessonAsTaught->execute($lesson, $this->user($request));

        return back()->with('success', 'Aula marcada como lecionada.');
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

        $this->clearLessonSummary->execute($lesson, $this->user($request));

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
            LessonStatus::Prepared => 'Preparado',
            LessonStatus::Taught => 'Lecionado',
        };
    }
}
