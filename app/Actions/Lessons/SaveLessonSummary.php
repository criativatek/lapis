<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveLessonSummary
{
    public function __construct(
        protected AuditLog $audit,
        protected SaveLessonAttendanceDraft $saveAttendanceDraft,
    ) {}

    /**
     * `content` is optional here rather than required: on the create path
     * below (no existing summary) it must be present — `content` is NOT NULL
     * at the database level — but on the update path, fill() below leaves
     * any key the caller omits completely untouched, which is exactly what
     * ApplyLessonSequence relies on to leave an existing lesson's unselected
     * fields alone.
     *
     * UMA CAIXA VAZIA NUMA AULA AINDA NÃO LECIONADA É «LIMPAR» (0.194.2): o
     * professor que apaga o texto e carrega em «Guardar» quer a aula sem
     * sumário — não quer ouvir que «o campo content é obrigatório». O texto
     * sai (a linha some, se mais nada lá estiver), a aula volta a «Por
     * preparar» e a auditoria diz `lesson.summary_cleared`, como no botão
     * «Limpar sumário». Numa aula lecionada o sumário continua obrigatório.
     *
     * @param  array{content?: string, private_notes?: string|null, resources?: string|null, homework?: string|null}  $details
     * @param  list<string>|null  $absentStudentUlids  o rascunho de faltas, gravado na MESMA transação do sumário quando vem — nunca quando a assiduidade já está consolidada, ver SaveLessonAttendanceDraft::apply()
     * @return LessonSummary|null NULL só quando a aula fica sem linha de sumário (caixa vazia, nada mais a guardar)
     */
    public function execute(
        Lesson $lesson,
        array $details,
        User $actor,
        ?array $absentStudentUlids = null,
        ?int $expectedVersion = null,
    ): ?LessonSummary {
        return DB::transaction(function () use ($absentStudentUlids, $actor, $details, $expectedVersion, $lesson): ?LessonSummary {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            // Bloqueio otimista: a versão que o ecrã viu tem de ser a que está
            // gravada AGORA, com a aula já trancada e antes de qualquer
            // escrita — incluindo o rascunho de faltas. Os chamadores
            // internos (sequências, importação) não passam versão.
            if ($expectedVersion !== null && $expectedVersion !== $lockedLesson->summary_version) {
                throw self::staleVersion();
            }

            // UMA AULA QUE NÃO SE REALIZOU NÃO TEM SUMÁRIO (0.194.1). Professor ausente,
            // turma noutra atividade, aula não realizada: o resultado já disse o que
            // aconteceu e o planeamento desceu para a aula seguinte. Aceitar texto aqui
            // deixava o professor «obrigado» a escrever o sumário de uma aula que não
            // houve — e a semana a mostrá-lo como se tivesse havido.
            if ($lockedLesson->outcome !== null && $lockedLesson->outcome !== LessonOutcome::Taught) {
                throw self::notApplicable($lockedLesson);
            }

            // Consolidada, a lista vem só como eco do ecrã: as correções têm rota
            // própria, e gravar notas ou recursos não pode falhar por causa dela.
            if ($absentStudentUlids !== null && ! $lockedLesson->attendanceRecorded() && ! $lockedLesson->attendanceNotApplicable()) {
                $this->saveAttendanceDraft->apply($lockedLesson, $absentStudentUlids, $actor);
            }

            if (array_key_exists('content', $details) && trim((string) $details['content']) === '') {
                if ($lockedLesson->isTaught()) {
                    throw self::summaryRequired();
                }

                return $this->clearContent($lockedLesson, $details, $actor);
            }

            $summary = $lockedLesson->summary()->first();
            $isPostTaughtEdit = $lockedLesson->status === LessonStatus::Taught;

            if ($summary === null) {
                if ($isPostTaughtEdit) {
                    $details['reviewed_at'] = now();
                    $details['reviewed_by'] = $actor->getKey();
                }

                $summary = $lockedLesson->summary()->create($details);
            } else {
                $summary->fill($details);

                if ($isPostTaughtEdit) {
                    $summary->reviewed_at = now();
                    $summary->reviewed_by = $actor->getKey();
                }

                $summary->save();
            }

            if ($lockedLesson->status === LessonStatus::Preparation) {
                $lockedLesson->status = LessonStatus::Prepared;
                $lockedLesson->save();

                $this->audit->record(
                    'lesson.prepared',
                    $lockedLesson,
                    $actor,
                    'Estado da aula alterado.',
                    [
                        'from_status' => LessonStatus::Preparation->value,
                        'to_status' => LessonStatus::Prepared->value,
                    ],
                );
            } elseif ($lockedLesson->status === LessonStatus::Prepared) {
                // Já preparada, sem transição de estado — o que
                // `lesson.prepared` acima não cobre: um segundo (ou terceiro)
                // guardar do mesmo sumário, sem que nada mais mude.
                $this->audit->record(
                    'lesson.summary_saved',
                    $lockedLesson,
                    $actor,
                    'Sumário de aula guardado.',
                    ['summary_id' => $summary->getKey()],
                );
            }

            if ($isPostTaughtEdit) {
                $this->audit->record(
                    'lesson.summary_reviewed',
                    $lockedLesson,
                    $actor,
                    'Sumário de aula revisto.',
                    [
                        'lesson_status' => $lockedLesson->status->value,
                        'summary_id' => $summary->getKey(),
                    ],
                );
            }

            return $summary;
        });
    }

    /**
     * A caixa vazia numa aula ainda não lecionada: o texto sai; as notas, os
     * recursos e o TPC que vierem no mesmo pedido ficam (a linha mantém-se com
     * `content` vazio, como ClearLessonSummary); sem nada para manter, a linha
     * some. «Preparada» volta a «Por preparar». Sem linha antes nem depois,
     * não há nada a registar.
     *
     * @param  array{content?: string, private_notes?: string|null, resources?: string|null, homework?: string|null}  $details
     */
    private function clearContent(Lesson $lockedLesson, array $details, User $actor): ?LessonSummary
    {
        /** @var LessonSummary|null $summary */
        $summary = $lockedLesson->summary()->first();
        $optional = array_intersect_key($details, array_flip(['private_notes', 'resources', 'homework']));
        $optionalGiven = array_filter($optional, fn (?string $value): bool => trim((string) $value) !== '') !== [];

        if ($summary === null) {
            if (! $optionalGiven) {
                return null;
            }

            $summary = $lockedLesson->summary()->create(['content' => ''] + $optional);
        } else {
            $summary->fill(['content' => ''] + $optional);

            $keepsOtherContent = trim((string) $summary->private_notes) !== ''
                || trim((string) $summary->resources) !== ''
                || trim((string) $summary->homework) !== '';

            if ($keepsOtherContent) {
                $summary->save();
            } else {
                $summary->delete();
                $summary = null;
            }
        }

        if ($lockedLesson->status === LessonStatus::Prepared) {
            $lockedLesson->status = LessonStatus::Preparation;
            $lockedLesson->save();
        }

        $this->audit->record(
            'lesson.summary_cleared',
            $lockedLesson,
            $actor,
            'Sumário de aula limpo.',
            [
                'kept_optional_details' => $summary !== null,
                'to_status' => $lockedLesson->status->value,
            ],
        );

        return $summary;
    }

    /**
     * Numa aula lecionada o sumário é o registo do que se fez: não pode ficar vazio.
     */
    public static function summaryRequired(): ValidationException
    {
        return ValidationException::withMessages([
            'content' => __('Escreve o sumário desta aula.'),
        ]);
    }

    public static function notApplicable(Lesson $lesson): ValidationException
    {
        return ValidationException::withMessages([
            'content' => __('Esta aula não se realizou (:outcome): não tem sumário. O que estava planeado passou para a aula seguinte.', [
                'outcome' => (string) $lesson->outcome?->label(),
            ]),
        ]);
    }

    /**
     * A mesma recusa para quem grava e para quem limpa (ClearLessonSummary).
     */
    public static function staleVersion(): ValidationException
    {
        return ValidationException::withMessages([
            'summary_version' => __('O sumário desta aula foi alterado noutra janela depois de o abrires. O teu texto não foi gravado.'),
        ]);
    }
}
