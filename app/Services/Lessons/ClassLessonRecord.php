<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use Carbon\CarbonImmutable;

/**
 * «Quantas aulas houve, e de que espécie» (0.146.0) — a contagem das
 * ocorrências de uma turma num intervalo, para o relatório de turma.
 *
 * SETE NÚMEROS, NENHUM INFERIDO (e a causa agregada das não realizadas):
 *  - previstas: todas as ocorrências gravadas no intervalo (até hoje);
 *  - contabilizadas como lecionadas: lecionadas + turma noutra atividade;
 *  - com desenvolvimento efetivo da disciplina: só as lecionadas;
 *  - professor ausente;
 *  - turma em outras atividades letivas;
 *  - aula não realizada (greve, escola sem condições…): prevista e não dada,
 *    fora da contagem de lecionadas e SEM revelar a causa — essa é reservada a
 *    quem a registou;
 *  - por registar: ocorrências já passadas ainda sem resultado.
 *
 * Previstas = contabilizadas + professor ausente + não realizadas + por registar. Uma aula
 * futura sem resultado não está «por registar»: ainda não aconteceu, e não
 * entra em nenhum dos números.
 *
 * O motivo de uma ausência, a especificação perante a greve e a descrição de
 * «Outra causa» NUNCA saem daqui: são dados sobre o professor. Sai a causa
 * agregada de uma aula não realizada («Greve: 2»), que descreve a turma.
 */
final class ClassLessonRecord
{
    private const TIMEZONE = 'Europe/Lisbon';

    /**
     * @return array{
     *     totals: array{planned: int, counted_as_taught: int, subject_development: int, teacher_absent: int, class_external_activity: int, not_held: int, not_recorded: int},
     *     rows: list<array{date: string, context_label: string, lesson_number: int|null, lesson_number_end: int|null, outcome: string, note: string|null}>,
     *     causes: list<array{cause: string, label: string, count: int}>,
     * }
     */
    public function for(SchoolClass $class, ?string $from = null, ?string $to = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(self::TIMEZONE);
        $today = $now->setTimezone(self::TIMEZONE)->toDateString();
        $until = $to === null || $to > $today ? $today : $to;

        $lessons = Lesson::query()
            ->where('class_id', $class->getKey())
            ->when($from !== null, fn ($query) => $query->whereDate('starts_at', '>=', $from))
            ->whereDate('starts_at', '<=', $until)
            ->with(['schoolClass', 'classGroup'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $totals = ['planned' => 0, 'counted_as_taught' => 0, 'subject_development' => 0, 'teacher_absent' => 0, 'class_external_activity' => 0, 'not_held' => 0, 'not_recorded' => 0];
        $rows = [];
        $causes = [];

        foreach ($lessons as $lesson) {
            $outcome = $lesson->outcome ?? ($lesson->status === LessonStatus::Taught ? LessonOutcome::Taught : null);

            if ($outcome === null && $lesson->starts_at->greaterThan($now)) {
                continue;
            }

            $totals['planned']++;

            if ($outcome === null) {
                $totals['not_recorded']++;
            } else {
                $totals['counted_as_taught'] += $outcome->countsAsTaught() ? 1 : 0;
                $totals['subject_development'] += $outcome->developsSubject() ? 1 : 0;
                $totals['teacher_absent'] += $outcome === LessonOutcome::TeacherAbsent ? 1 : 0;
                $totals['class_external_activity'] += $outcome === LessonOutcome::ClassExternalActivity ? 1 : 0;
                $totals['not_held'] += $outcome === LessonOutcome::NotHeld ? 1 : 0;

                // Só a CAUSA agregada («Greve»): nunca quem a registou, a
                // especificação perante a greve nem a descrição de «Outra causa».
                if ($outcome === LessonOutcome::NotHeld && $lesson->outcome_cause !== null) {
                    $causes[$lesson->outcome_cause->value] = ($causes[$lesson->outcome_cause->value] ?? 0) + 1;
                }
            }

            $rows[] = [
                'date' => $lesson->starts_at->copy()->setTimezone(self::TIMEZONE)->toDateString(),
                'context_label' => $lesson->contextLabel(),
                'lesson_number' => $lesson->lesson_number,
                'lesson_number_end' => $lesson->lesson_number_end,
                'outcome' => $outcome->value ?? 'not_recorded',
                'note' => $outcome === LessonOutcome::ClassExternalActivity ? $lesson->outcome_note : null,
            ];
        }

        arsort($causes);

        return [
            'totals' => $totals,
            'rows' => $rows,
            'causes' => array_map(
                fn (string $cause, int $count): array => ['cause' => $cause, 'label' => LessonNotHeldCause::from($cause)->label(), 'count' => $count],
                array_keys($causes),
                $causes,
            ),
        ];
    }
}
