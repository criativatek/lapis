<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\LessonOutcomeRectification;
use App\Models\SchoolClass;
use App\Models\TeacherAbsenceReason;
use App\Models\TeacherStrikeSituation;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CORRIGIR o resultado de uma aula que já não estava aberta. Corrigir não é
 * registar de novo: a aula continua fechada, e nada do que depende da sua
 * posição se mexe — números de lição, planeamento, sumário, faltas.
 *
 * Duas correções, as duas com histórico (LessonOutcomeRectification):
 *
 *  A. DETALHES do mesmo resultado — a causa de uma aula não realizada, a
 *     especificação facultativa da greve, a descrição de «outra causa», o
 *     motivo de uma ausência, a descrição de uma atividade da turma.
 *  B. O RESULTADO — só entre «Professor ausente» e «Aula não realizada». Os dois
 *     estão fora da numeração, não contam como lecionada, não levam assiduidade
 *     nem sumário, e o planeamento já desceu uma posição em ambos: trocar um
 *     pelo outro não tem nada para recalcular. Qualquer outra passagem (de ou
 *     para «Lecionada», de ou para «Turma noutra atividade») mexeria na
 *     numeração ou no planeamento e é RECUSADA com a razão — nunca se renumera
 *     em silêncio uma aula consolidada.
 *
 * Só quem pode ler o dado original o corrige (LessonPolicy::rectifyOutcome): a
 * correção mostra e muda dado reservado. O autor do registo (`outcome_recorded_*`)
 * não muda; quem corrigiu, e quando, fica no histórico.
 *
 * Numa transação, com a turma bloqueada, e com todas as recusas antes de
 * qualquer escrita.
 */
class RectifyLessonOutcome
{
    public const int MOTIVE_MIN = 3;

    public const int MOTIVE_MAX = 200;

    public function __construct(
        protected RecordLessonOutcome $recorder,
        protected AuditLog $audit,
    ) {}

    /**
     * O resultado para onde uma aula com este resultado se pode corrigir (incluindo o próprio,
     * para corrigir os detalhes). Uma só fonte para o servidor e para o ecrã.
     *
     * @return list<LessonOutcome>
     */
    public static function targetsFor(LessonOutcome $current): array
    {
        return match ($current) {
            LessonOutcome::TeacherAbsent, LessonOutcome::NotHeld => [LessonOutcome::TeacherAbsent, LessonOutcome::NotHeld],
            LessonOutcome::ClassExternalActivity => [LessonOutcome::ClassExternalActivity],
            LessonOutcome::Taught => [],
        };
    }

    /**
     * Porque é que o RESULTADO desta aula não se pode trocar (os detalhes sim), ou `null` se pode.
     */
    public static function outcomeLockedReason(LessonOutcome $current): ?string
    {
        return match ($current) {
            LessonOutcome::ClassExternalActivity => (string) __('Passar de «Turma noutra atividade» para outro resultado altera a numeração das lições e o planeamento: só se pode corrigir a descrição.'),
            LessonOutcome::Taught => (string) __('Uma aula lecionada tem sumário, faltas e número de lição: não se corrige por aqui.'),
            default => null,
        };
    }

    public function execute(
        Lesson $lesson,
        LessonOutcome $outcome,
        User $actor,
        string $motive,
        ?TeacherAbsenceReason $reason = null,
        ?string $note = null,
        ?LessonNotHeldCause $cause = null,
        ?TeacherStrikeSituation $situation = null,
        ?string $causeNote = null,
    ): LessonOutcomeRectification {
        return DB::transaction(function () use ($lesson, $outcome, $actor, $motive, $reason, $note, $cause, $situation, $causeNote): LessonOutcomeRectification {
            SchoolClass::query()->whereKey($lesson->class_id)->lockForUpdate()->first();

            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            $current = $locked->outcome;

            if ($current === null || $current === LessonOutcome::Taught) {
                throw ValidationException::withMessages([
                    'outcome' => $current === null
                        ? __('Esta aula ainda não tem resultado registado.')
                        : (string) self::outcomeLockedReason($current),
                ]);
            }

            if (! in_array($outcome, self::targetsFor($current), true)) {
                throw ValidationException::withMessages([
                    'outcome' => (string) (self::outcomeLockedReason($current) ?? __('Este resultado não se pode corrigir para o escolhido.')),
                ]);
            }

            $motive = trim($motive);

            if (mb_strlen($motive) < self::MOTIVE_MIN || mb_strlen($motive) > self::MOTIVE_MAX) {
                throw ValidationException::withMessages([
                    'rectification_note' => __('Indica o motivo da correção (entre :min e :max carateres).', ['min' => self::MOTIVE_MIN, 'max' => self::MOTIVE_MAX]),
                ]);
            }

            $this->guardDetails($locked, $outcome, $reason, $cause, $situation, $causeNote);

            $previous = $this->snapshot($locked);

            $locked->outcome = $outcome;
            $locked->outcome_reason = $outcome === LessonOutcome::TeacherAbsent ? $reason : null;
            $locked->outcome_note = $outcome === LessonOutcome::ClassExternalActivity ? $this->recorder->cleanNote($note) : null;
            $locked->outcome_cause = $outcome === LessonOutcome::NotHeld ? $cause : null;
            $locked->outcome_teacher_situation = $outcome === LessonOutcome::NotHeld && $cause === LessonNotHeldCause::Strike ? $situation : null;
            $locked->outcome_cause_note = $outcome === LessonOutcome::NotHeld && $cause === LessonNotHeldCause::Other ? $this->recorder->cleanNote($causeNote) : null;

            $new = $this->snapshot($locked);

            if ($previous === $new) {
                throw ValidationException::withMessages([
                    'outcome' => __('Nada mudou: não há nada para corrigir.'),
                ]);
            }

            // Só os campos do resultado: números, planeamento, sumário e assiduidade ficam como estão.
            $locked->save();

            $rectification = LessonOutcomeRectification::create([
                'lesson_id' => $locked->getKey(),
                'rectified_by' => $actor->getKey(),
                'rectified_at' => Carbon::now(),
                'kind' => $previous['outcome'] !== $new['outcome']
                    ? LessonOutcomeRectification::KIND_OUTCOME
                    : LessonOutcomeRectification::KIND_DETAILS,
                'previous' => $previous,
                'current' => $new,
                'motive' => $motive,
            ]);

            // SEM DADO RESERVADO NEM TEXTO: a auditoria geral diz que houve correção, o resultado e a causa
            // (que são da turma); a especificação, o motivo da ausência e o texto livre ficam só no histórico.
            $this->audit->record(
                'lesson.outcome_rectified',
                $locked,
                $actor,
                'Resultado da aula corrigido.',
                [
                    'kind' => $rectification->kind,
                    'from_outcome' => $previous['outcome'],
                    'to_outcome' => $new['outcome'],
                    'from_cause' => $previous['cause'],
                    'to_cause' => $new['cause'],
                    'reserved_details_changed' => collect(['reason', 'situation', 'cause_note', 'note'])
                        ->contains(fn (string $field): bool => $previous[$field] !== $new[$field]),
                ],
            );

            return $rectification;
        });
    }

    /**
     * CORREÇÃO ADMINISTRATIVA EXCECIONAL: o titular do registo já não pertence à turma e a causa de uma
     * aula não realizada está errada. Quem a faz é a direção da instituição (LessonPolicy::rectifyOutcomeAsAdministrator),
     * que NÃO lê os detalhes reservados do titular — e por isso aqui só se pode mudar a CAUSA, que é da turma:
     *
     *  - nunca o resultado (um motivo de ausência é um dado sobre uma pessoa concreta);
     *  - nunca se declara uma especificação perante a greve em nome de ninguém (fica vazia);
     *  - o que o titular tinha reservado (especificação, descrição) guarda-se no histórico, onde a direção
     *    não o lê, e deixa de estar na aula porque deixou de se aplicar à nova causa.
     *
     * Mesmos limites de integridade da correção normal: numa transação, sem tocar em números, planeamento,
     * sumário nem assiduidade; motivo obrigatório; autor e histórico guardados (`kind = administrative`).
     */
    public function executeAsAdministrator(
        Lesson $lesson,
        User $actor,
        LessonNotHeldCause $cause,
        string $motive,
        ?string $causeNote = null,
    ): LessonOutcomeRectification {
        return DB::transaction(function () use ($lesson, $actor, $cause, $motive, $causeNote): LessonOutcomeRectification {
            SchoolClass::query()->whereKey($lesson->class_id)->lockForUpdate()->first();

            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            if ($locked->outcome !== LessonOutcome::NotHeld) {
                throw ValidationException::withMessages([
                    'outcome' => __('A direção só corrige a causa de uma aula não realizada.'),
                ]);
            }

            if ($locked->outcome_cause === $cause) {
                throw ValidationException::withMessages([
                    'cause' => __('Nada mudou: não há nada para corrigir.'),
                ]);
            }

            $motive = trim($motive);

            if (mb_strlen($motive) < self::MOTIVE_MIN || mb_strlen($motive) > self::MOTIVE_MAX) {
                throw ValidationException::withMessages([
                    'rectification_note' => __('Indica o motivo da correção (entre :min e :max carateres).', ['min' => self::MOTIVE_MIN, 'max' => self::MOTIVE_MAX]),
                ]);
            }

            // Sem especificação perante a greve: a direção não declara nada em nome de um professor.
            $this->recorder->guardDetails(LessonOutcome::NotHeld, null, $cause, null, $causeNote);

            $previous = $this->snapshot($locked);

            $locked->outcome_cause = $cause;
            $locked->outcome_teacher_situation = null;
            $locked->outcome_cause_note = $cause === LessonNotHeldCause::Other ? $this->recorder->cleanNote($causeNote) : null;
            $new = $this->snapshot($locked);
            $locked->save();

            $rectification = LessonOutcomeRectification::create([
                'lesson_id' => $locked->getKey(),
                'rectified_by' => $actor->getKey(),
                'rectified_at' => Carbon::now(),
                'kind' => LessonOutcomeRectification::KIND_ADMINISTRATIVE,
                'previous' => $previous,
                'current' => $new,
                'motive' => $motive,
            ]);

            $this->audit->record(
                'lesson.outcome_rectified',
                $locked,
                $actor,
                'Causa da aula corrigida pela direção.',
                [
                    'kind' => LessonOutcomeRectification::KIND_ADMINISTRATIVE,
                    'from_outcome' => $previous['outcome'],
                    'to_outcome' => $new['outcome'],
                    'from_cause' => $previous['cause'],
                    'to_cause' => $new['cause'],
                    'reserved_details_changed' => $previous['situation'] !== null || $previous['cause_note'] !== $new['cause_note'],
                ],
            );

            return $rectification;
        });
    }

    private function guardDetails(
        Lesson $locked,
        LessonOutcome $outcome,
        ?TeacherAbsenceReason $reason,
        ?LessonNotHeldCause $cause,
        ?TeacherStrikeSituation $situation,
        ?string $causeNote,
    ): void {
        // Uma especificação que a aula JÁ TEM (por exemplo «Aderi», gravada quando a chave estava
        // ligada) não deixa de poder ficar só porque a chave entretanto se desligou: a chave governa o
        // que se oferece, não o que se guarda. Continua a exigir a greve.
        $keepsStored = $situation !== null
            && $situation === $locked->outcome_teacher_situation
            && ! in_array($situation, TeacherStrikeSituation::available(), true);

        $this->recorder->guardDetails($outcome, $reason, $cause, $keepsStored ? null : $situation, $causeNote);

        if ($keepsStored && ($outcome !== LessonOutcome::NotHeld || $cause !== LessonNotHeldCause::Strike)) {
            throw ValidationException::withMessages([
                'situation' => __('A situação perante a greve só existe quando a causa é greve.'),
            ]);
        }
    }

    /**
     * @return array{outcome: string|null, reason: string|null, note: string|null, cause: string|null, situation: string|null, cause_note: string|null}
     */
    public function snapshot(Lesson $lesson): array
    {
        return [
            'outcome' => $lesson->outcome?->value,
            'reason' => $lesson->outcome_reason?->value,
            'note' => $lesson->outcome_note,
            'cause' => $lesson->outcome_cause?->value,
            'situation' => $lesson->outcome_teacher_situation?->value,
            'cause_note' => $lesson->outcome_cause_note,
        ];
    }
}
