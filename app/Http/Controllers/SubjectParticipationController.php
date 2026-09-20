<?php

namespace App\Http\Controllers;

use App\Actions\SubjectParticipation\DeleteExternalSubjectResult;
use App\Actions\SubjectParticipation\MarkNotAttendingSubject;
use App\Actions\SubjectParticipation\ReactivateSubjectParticipation;
use App\Actions\SubjectParticipation\RecordExternalSubjectResult;
use App\Http\Requests\SubjectParticipation\MarkNotAttendingSubjectRequest;
use App\Http\Requests\SubjectParticipation\ReactivateSubjectParticipationRequest;
use App\Http\Requests\SubjectParticipation\RecordExternalSubjectResultRequest;
use App\Models\Enrollment;
use App\Models\ExternalSubjectResult;
use App\Models\SchoolClass;
use App\Models\SubjectParticipation;
use App\Models\SubjectParticipationReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A frequência de uma disciplina e os resultados externos, vistos do ecrã da
 * turma — abrir/fechar uma janela de não-frequência, e registar/apagar uma
 * classificação obtida fora do sistema.
 *
 * FINO DE PROPÓSITO: cada método resolve a inscrição (ou o resultado)
 * ATRAVÉS DA TURMA da rota — nunca por id solto — e delega às ações já
 * existentes em `App\Actions\SubjectParticipation`, que já fazem lock,
 * re-check e escrita dentro de uma transação. Mesmo idioma que
 * `ClassGroupController` já usa para grupos.
 */
class SubjectParticipationController extends Controller implements HasMiddleware
{
    public function __construct(
        protected MarkNotAttendingSubject $markNotAttending,
        protected ReactivateSubjectParticipation $reactivateParticipation,
        protected RecordExternalSubjectResult $recordResult,
        protected DeleteExternalSubjectResult $deleteResult,
    ) {}

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:classes'];
    }

    public function store(MarkNotAttendingSubjectRequest $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('manage', [SubjectParticipation::class, $class]);

        $enrollment = $this->enrollmentOf($class, (int) $request->validated('enrollment_id'));

        // M3: SEM AUDITORIA AQUI. `MarkNotAttendingSubject::execute()` já
        // regista `subject_participation.marked_not_attending` — a Action é
        // a dona do rasto de auditoria (ver o docblock de `AuditLog`), e
        // repeti-lo aqui só produzia dois registos para o mesmo evento.
        return $this->reportingValidationErrors(function () use ($request, $enrollment): void {
            $this->markNotAttending->execute(
                $enrollment,
                SubjectParticipationReason::from($request->validated('reason')),
                (string) $request->validated('effective_from'),
                $request->validated('reason_detail'),
                $request->validated('note'),
            );
        });
    }

    public function reactivate(ReactivateSubjectParticipationRequest $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('manage', [SubjectParticipation::class, $class]);

        $enrollment = $this->enrollmentOf($class, (int) $request->validated('enrollment_id'));

        // M3: SEM AUDITORIA AQUI — `ReactivateSubjectParticipation::execute()`
        // já regista `subject_participation.reactivated`.
        return $this->reportingValidationErrors(function () use ($request, $enrollment): void {
            $this->reactivateParticipation->execute(
                $enrollment,
                (string) $request->validated('effective_from'),
            );
        });
    }

    public function storeResult(RecordExternalSubjectResultRequest $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('manage', [SubjectParticipation::class, $class]);

        $enrollment = $this->enrollmentOf($class, (int) $request->validated('enrollment_id'));

        // M3: SEM AUDITORIA AQUI — `RecordExternalSubjectResult::execute()` já
        // regista `external_result.recorded`/`external_result.updated`.
        return $this->reportingValidationErrors(function () use ($request, $enrollment): void {
            $this->recordResult->execute(
                $enrollment,
                $request->validated('period_id') === null ? null : (int) $request->validated('period_id'),
                (string) $request->validated('origin'),
                (string) $request->validated('recorded_on'),
                $request->validated('scale_level_id') === null ? null : (int) $request->validated('scale_level_id'),
                $request->validated('level_code'),
                $request->validated('numeric_value') === null ? null : (string) $request->validated('numeric_value'),
                $request->validated('note'),
            );
        });
    }

    public function destroyResult(Request $request, SchoolClass $class, ExternalSubjectResult $externalSubjectResult): RedirectResponse
    {
        Gate::authorize('manage', [SubjectParticipation::class, $class]);
        $this->assertResultBelongsTo($class, $externalSubjectResult);

        // M3: SEM AUDITORIA AQUI, E SEM APAGAR PRIMEIRO — `DeleteExternalSubjectResult::execute()`
        // já regista `external_result.deleted` ANTES de `$result->delete()`,
        // que é a única ordem em que a linha ainda existe para ser lida.
        $this->deleteResult->execute($externalSubjectResult);

        return back();
    }

    /**
     * Uma recusa que o professor tem de ler É UM TOAST — mesmo idioma que
     * `ClassGroupController::reportingValidationErrors()` já usa.
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
     * A inscrição resolvida ATRAVÉS da turma da rota — nunca por id solto,
     * pela mesma razão que `ClassGroupController::enrollmentOf()` já
     * documenta.
     */
    protected function enrollmentOf(SchoolClass $class, int $enrollmentId): Enrollment
    {
        /** @var Enrollment|null $enrollment */
        $enrollment = $class->enrollments()->whereKey($enrollmentId)->first();

        abort_if($enrollment === null, 404);

        return $enrollment;
    }

    /**
     * O resultado externo tem de pertencer a uma inscrição desta turma — um
     * ulid de outra turma (ou de outra organização, que o global scope já
     * esconde) não encontra nada e é 404, nunca apagado.
     */
    protected function assertResultBelongsTo(SchoolClass $class, ExternalSubjectResult $result): void
    {
        $belongs = $class->enrollments()->whereKey($result->enrollment_id)->exists();

        abort_unless($belongs, 404);
    }
}
