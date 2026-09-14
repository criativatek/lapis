<?php

namespace App\Http\Controllers;

use App\Actions\ClassGroups\ArchiveClassGroup;
use App\Actions\ClassGroups\AssignClassGroupMemberships;
use App\Actions\ClassGroups\MoveClassGroupMembership;
use App\Actions\ClassGroups\SwapClassGroupMembership;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\ClassGroups\ClassGroupAssignmentRequest;
use App\Http\Requests\ClassGroups\ClassGroupMoveRequest;
use App\Http\Requests\ClassGroups\ClassGroupRequest;
use App\Http\Requests\ClassGroups\ClassGroupSwapRequest;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\Audit\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A secção «Grupos» do ecrã da turma.
 *
 * NÃO HÁ ECRÃ PRÓPRIO E NÃO HÁ ASSISTENTE. Tudo isto acontece na página da
 * turma, que continua sempre reeditável: criar, renomear, ordenar, distribuir,
 * mover, permutar e arquivar são ações que devolvem o professor exatamente ao
 * sítio onde estava (§8, §25 do briefing). Nenhuma delas depende do botão
 * «voltar» do browser, e nenhuma exige apagar a turma para corrigir um engano.
 *
 * `module:lessons` E NÃO `module:classes`: um grupo só existe para o horário.
 * Um professor do plano base, sem o módulo das aulas, não tem grupos porque
 * não tem tempos onde os usar — e a secção nem sequer lhe aparece.
 */
class ClassGroupController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected AssignClassGroupMemberships $assignMemberships,
        protected MoveClassGroupMembership $moveMembership,
        protected SwapClassGroupMembership $swapMemberships,
        protected ArchiveClassGroup $archiveClassGroup,
        protected AuditLog $audit,
    ) {}

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function store(ClassGroupRequest $request, SchoolClass $class): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        // No fim da lista, e calculado a partir do que lá está: um grupo novo
        // não se mete entre os que o professor já ordenou.
        $position = (int) $class->classGroups()->max('position') + 1;

        $classGroup = ClassGroup::create([
            'class_id' => $class->getKey(),
            'label' => $request->validated('label'),
            'position' => $position,
        ]);

        $this->audit->record(
            'class_group.created',
            $classGroup,
            $request->user(),
            "Grupo «{$classGroup->label}» criado — {$class->label}.",
            ['class_id' => $class->id, 'class_group_id' => $classGroup->id],
        );

        return back();
    }

    public function update(ClassGroupRequest $request, SchoolClass $class, ClassGroup $classGroup): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);
        $this->assertBelongsTo($class, $classGroup);

        // Renomear um grupo arquivado é permitido de propósito: corrigir uma
        // gralha num rótulo que já aparece em aulas antigas melhora a leitura
        // dessas aulas, e não altera nenhuma pertença nem nenhum instantâneo.
        $classGroup->update(['label' => $request->validated('label')]);

        if ($classGroup->wasChanged()) {
            $this->audit->record(
                'class_group.updated',
                $classGroup,
                $request->user(),
                "Grupo «{$classGroup->label}» alterado — {$class->label}.",
                ['class_id' => $class->id, 'class_group_id' => $classGroup->id],
            );
        }

        return back();
    }

    /**
     * A ordem por que os grupos aparecem — a do professor, não a de criação.
     *
     * Uma transação para a lista inteira: uma reordenação meia-feita deixaria
     * duas posições iguais e uma lista que salta de cada vez que a página
     * carrega.
     */
    public function reorder(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('create', [ClassGroup::class, $class]);
        $this->refuseDuringImpersonation($request);

        $validated = $request->validate([
            'ulids' => ['present', 'array', 'max:100'],
            'ulids.*' => ['required', 'string'],
        ]);

        DB::transaction(function () use ($class, $validated): void {
            foreach ($validated['ulids'] as $position => $ulid) {
                // Resolvido ATRAVÉS da turma: um ulid de outra turma — ou de
                // outra organização, que o global scope já esconde — não
                // encontra nada e é ignorado, nunca escrito. É o mesmo idioma
                // que EnrollmentController::updateProcessNumbers() já usa.
                $class->classGroups()->where('ulid', $ulid)->update(['position' => $position]);
            }
        });

        return back();
    }

    /**
     * Apagar SÓ o que ainda não é nada.
     *
     * Um grupo sem uma única pertença (nem aberta nem fechada), sem tempos do
     * horário e sem aulas nunca existiu para ninguém: apagá-lo é desfazer uma
     * gralha, e é o que mantém a criação de grupos reversível. Tudo o resto
     * arquiva-se — e é a própria ação que o diz, em vez de deixar a chave
     * estrangeira RESTRICT subir como um 500.
     */
    public function destroy(Request $request, SchoolClass $class, ClassGroup $classGroup): RedirectResponse
    {
        Gate::authorize('delete', $classGroup);
        $this->refuseDuringImpersonation($request);
        $this->assertBelongsTo($class, $classGroup);

        $hasHistory = $classGroup->memberships()->exists()
            || $classGroup->recurringLessonSlots()->exists()
            || $classGroup->lessons()->exists();

        if ($hasHistory) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('O grupo :label já tem história e não pode ser apagado. Arquive-o — os alunos, os tempos do horário e as aulas continuam a lê-lo.', [
                    'label' => $classGroup->label,
                ]),
            ]);

            return back();
        }

        $classGroupId = $classGroup->id;
        $label = $classGroup->label;

        $classGroup->delete();

        $this->audit->record(
            'class_group.deleted',
            $classGroup,
            $request->user(),
            "Grupo «{$label}» eliminado — {$class->label}.",
            ['class_id' => $class->id, 'class_group_id' => $classGroupId],
        );

        return back();
    }

    public function archive(Request $request, SchoolClass $class, ClassGroup $classGroup): RedirectResponse
    {
        Gate::authorize('update', $classGroup);
        $this->refuseDuringImpersonation($request);
        $this->assertBelongsTo($class, $classGroup);

        $wasArchived = $classGroup->isArchived();

        $response = $this->reportingValidationErrors(
            fn () => $this->archiveClassGroup->execute($classGroup),
        );

        if (! $wasArchived && $classGroup->fresh()?->isArchived()) {
            $this->audit->record(
                'class_group.archived',
                $classGroup,
                $request->user(),
                "Grupo «{$classGroup->label}» arquivado — {$class->label}.",
                ['class_id' => $class->id, 'class_group_id' => $classGroup->id],
            );
        }

        return $response;
    }

    public function restore(Request $request, SchoolClass $class, ClassGroup $classGroup): RedirectResponse
    {
        Gate::authorize('update', $classGroup);
        $this->refuseDuringImpersonation($request);
        $this->assertBelongsTo($class, $classGroup);

        $wasArchived = $classGroup->isArchived();

        $this->archiveClassGroup->restore($classGroup);

        if ($wasArchived) {
            $this->audit->record(
                'class_group.restored',
                $classGroup,
                $request->user(),
                "Grupo «{$classGroup->label}» restaurado — {$class->label}.",
                ['class_id' => $class->id, 'class_group_id' => $classGroup->id],
            );
        }

        return back();
    }

    public function assign(ClassGroupAssignmentRequest $request, SchoolClass $class): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $assignmentMap = $request->assignmentMap();

        $this->assignMemberships->execute($class, $assignmentMap);

        $this->audit->record(
            'class_group.students_assigned',
            $class,
            $request->user(),
            "Alunos distribuídos por grupos — {$class->label}.",
            ['class_id' => $class->id, 'count' => count($assignmentMap)],
        );

        return back();
    }

    public function move(ClassGroupMoveRequest $request, SchoolClass $class): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $enrollment = $this->enrollmentOf($class, $request->integer('enrollment_id'));
        $groupId = $request->validated('class_group_id');

        $this->moveMembership->execute(
            $enrollment,
            $groupId === null ? null : $this->groupOf($class, (int) $groupId),
            (string) $request->validated('effective_from'),
        );

        $this->audit->record(
            'class_group.students_assigned',
            $class,
            $request->user(),
            "Aluno movido de grupo — {$class->label}.",
            ['class_id' => $class->id, 'count' => 1],
        );

        return back();
    }

    public function swap(ClassGroupSwapRequest $request, SchoolClass $class): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $this->swapMemberships->execute(
            $this->enrollmentOf($class, $request->integer('first_enrollment_id')),
            $this->enrollmentOf($class, $request->integer('second_enrollment_id')),
            (string) $request->validated('effective_from'),
        );

        $this->audit->record(
            'class_group.students_assigned',
            $class,
            $request->user(),
            "Alunos permutados entre grupos — {$class->label}.",
            ['class_id' => $class->id, 'count' => 2],
        );

        return back();
    }

    /**
     * Uma recusa que o professor tem de ler É UM TOAST, não um 422.
     *
     * As ações lançam ValidationException porque é assim que uma regra de
     * negócio recusa nesta aplicação; mas «este grupo tem tempos no horário»
     * não pertence a nenhum campo do formulário que a desencadeou — nem sequer
     * há formulário, é um botão. Apresentada como erro de campo, ficaria presa
     * a um `<InputError>` que ninguém está a ver.
     */
    protected function reportingValidationErrors(callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => collect($exception->errors())->flatten()->first() ?? $exception->getMessage(),
            ]);
        }

        return back();
    }

    /**
     * A inscrição resolvida ATRAVÉS da turma da rota — nunca por id solto.
     *
     * O `BelongsToCurrentOrganization` do form request já garantiu a
     * organização; o que falta é a turma, e uma inscrição de OUTRA turma minha
     * passaria essa regra sem problema nenhum. 404, como
     * EnrollmentController::destroy() faz com o mesmo caso.
     */
    protected function enrollmentOf(SchoolClass $class, int $enrollmentId): Enrollment
    {
        /** @var Enrollment|null $enrollment */
        $enrollment = $class->enrollments()->whereKey($enrollmentId)->first();

        abort_if($enrollment === null, 404);

        return $enrollment;
    }

    protected function groupOf(SchoolClass $class, int $classGroupId): ClassGroup
    {
        /** @var ClassGroup|null $classGroup */
        $classGroup = $class->classGroups()->whereKey($classGroupId)->first();

        abort_if($classGroup === null, 404);

        return $classGroup;
    }

    protected function assertBelongsTo(SchoolClass $class, ClassGroup $classGroup): void
    {
        abort_unless($classGroup->class_id === $class->getKey(), 404);
    }
}
