<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\SchoolClass;
use App\Models\TeacherStrikeSituation;
use App\Models\User;
use App\Support\Lessons\LessonOutcomeDetails;
use Carbon\CarbonImmutable;

/**
 * O relatório do SERVIÇO LETIVO do professor numa turma: quantas aulas havia,
 * quantas deu, quantas não se realizaram e porquê, e como isso evoluiu.
 *
 * Reutiliza `ClassLessonRecord` para as contagens (UMA fonte; o relatório de
 * turma e este não podem discordar) e acrescenta:
 *
 *  - a PERCENTAGEM DE AULAS DADAS = aulas contabilizadas como lecionadas ÷
 *    aulas previstas × 100. As não realizadas ficam no denominador. As aulas
 *    ainda SEM REGISTO vêm à parte (`not_recorded`) — durante o período
 *    letivo, a percentagem lê-se com elas em conta;
 *  - a evolução MENSAL dos mesmos números;
 *  - o bloco PESSOAL: as causas e a especificação perante a greve das aulas
 *    registadas por este professor (`LessonOutcomeDetails::mayReadReason`).
 *    O que outros registaram só conta na causa agregada que a turma já lê.
 *
 * «Aulas dadas» segue a definição do relatório de turma (lecionadas + turma
 * noutra atividade letiva). `subject_development` — só as lecionadas — vem
 * ao lado, para quem quiser o indicador mais estrito.
 */
final class TeacherLessonServiceReport
{
    private const TIMEZONE = 'Europe/Lisbon';

    public function __construct(private readonly ClassLessonRecord $record) {}

    /**
     * @return array{
     *     totals: array<string, int>,
     *     given_percentage: float|null,
     *     upcoming: int,
     *     causes: list<array{cause: string, label: string, count: int}>,
     *     evolution: list<array{month: string, planned: int, given: int, not_held: int, not_recorded: int, given_percentage: float|null}>,
     *     personal: array{
     *         not_held_recorded: int,
     *         not_held_by_others: int,
     *         causes: list<array{cause: string, label: string, count: int}>,
     *         strike: array{total: int, unspecified: int, adhered: int, not_adhered: int, not_informed: int},
     *         teacher_absent: int,
     *     },
     * }
     */
    public function for(User $teacher, SchoolClass $class, ?string $from = null, ?string $to = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(self::TIMEZONE);
        $record = $this->record->for($class, $from, $to, $now);
        $totals = $record['totals'];

        return [
            'totals' => $totals,
            'given_percentage' => $this->percentage($totals['counted_as_taught'], $totals['planned']),
            'upcoming' => $this->upcoming($class, $from, $to, $now),
            'causes' => $record['causes'],
            'evolution' => $this->evolution($record['rows']),
            'personal' => $this->personal($teacher, $class, $from, $to, $now, $totals['not_held']),
        ];
    }

    private function percentage(int $given, int $planned): ?float
    {
        return $planned === 0 ? null : round($given / $planned * 100, 1);
    }

    /** Aulas ainda por vir no intervalo: ainda não aconteceram, não entram em nenhum número. */
    private function upcoming(SchoolClass $class, ?string $from, ?string $to, CarbonImmutable $now): int
    {
        return Lesson::query()
            ->where('class_id', $class->getKey())
            ->whereNull('outcome')
            ->where('starts_at', '>', $now)
            ->when($from !== null, fn ($query) => $query->whereDate('starts_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('starts_at', '<=', $to))
            ->count();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{month: string, planned: int, given: int, not_held: int, not_recorded: int, given_percentage: float|null}>
     */
    private function evolution(array $rows): array
    {
        $months = [];

        foreach ($rows as $row) {
            $month = substr((string) $row['date'], 0, 7);
            $months[$month] ??= ['month' => $month, 'planned' => 0, 'given' => 0, 'not_held' => 0, 'not_recorded' => 0, 'given_percentage' => null];
            $months[$month]['planned']++;

            match ($row['outcome']) {
                LessonOutcome::Taught->value, LessonOutcome::ClassExternalActivity->value => $months[$month]['given']++,
                LessonOutcome::NotHeld->value => $months[$month]['not_held']++,
                'not_recorded' => $months[$month]['not_recorded']++,
                default => null,
            };
        }

        ksort($months);

        // Os meses SEM aulas entre o primeiro e o último aparecem a zero (férias, interrupções):
        // a evolução lê-se sem buracos, e um mês vazio não é um mês omitido.
        if ($months !== []) {
            $cursor = CarbonImmutable::parse(array_key_first($months).'-01');
            $last = CarbonImmutable::parse(array_key_last($months).'-01');

            while ($cursor->lessThanOrEqualTo($last)) {
                $key = $cursor->format('Y-m');
                $months[$key] ??= ['month' => $key, 'planned' => 0, 'given' => 0, 'not_held' => 0, 'not_recorded' => 0, 'given_percentage' => null];
                $cursor = $cursor->addMonth();
            }

            ksort($months);
        }

        return array_values(array_map(function (array $month): array {
            $month['given_percentage'] = $this->percentage($month['given'], $month['planned']);

            return $month;
        }, $months));
    }

    /**
     * Só o que ESTE professor registou (a regra do titular, num só sítio).
     *
     * @return array{
     *     not_held_recorded: int,
     *     not_held_by_others: int,
     *     causes: list<array{cause: string, label: string, count: int}>,
     *     strike: array{total: int, unspecified: int, adhered: int, not_adhered: int, not_informed: int},
     *     teacher_absent: int,
     * }
     */
    private function personal(User $teacher, SchoolClass $class, ?string $from, ?string $to, CarbonImmutable $now, int $notHeldTotal): array
    {
        $until = $to === null || $to > $now->toDateString() ? $now->toDateString() : $to;

        $lessons = Lesson::query()
            ->where('class_id', $class->getKey())
            ->whereIn('outcome', [LessonOutcome::NotHeld->value, LessonOutcome::TeacherAbsent->value])
            ->when($from !== null, fn ($query) => $query->whereDate('starts_at', '>=', $from))
            ->whereDate('starts_at', '<=', $until)
            ->with('schoolClass')
            ->get()
            ->filter(fn (Lesson $lesson): bool => LessonOutcomeDetails::mayReadReason($lesson, $teacher));

        $causes = [];
        $strike = ['total' => 0, 'unspecified' => 0, 'adhered' => 0, 'not_adhered' => 0, 'not_informed' => 0];
        $notHeld = 0;
        $absent = 0;

        foreach ($lessons as $lesson) {
            if ($lesson->outcome === LessonOutcome::TeacherAbsent) {
                $absent++;

                continue;
            }

            $notHeld++;

            if ($lesson->outcome_cause === null) {
                continue;
            }

            $causes[$lesson->outcome_cause->value] = ($causes[$lesson->outcome_cause->value] ?? 0) + 1;

            if ($lesson->outcome_cause === LessonNotHeldCause::Strike) {
                $strike['total']++;
                $strike[match ($lesson->outcome_teacher_situation) {
                    TeacherStrikeSituation::Adhered => 'adhered',
                    TeacherStrikeSituation::NotAdhered => 'not_adhered',
                    TeacherStrikeSituation::NotInformed => 'not_informed',
                    null => 'unspecified',
                }]++;
            }
        }

        arsort($causes);

        return [
            'not_held_recorded' => $notHeld,
            'not_held_by_others' => max(0, $notHeldTotal - $notHeld),
            'causes' => array_map(
                fn (string $cause, int $count): array => ['cause' => $cause, 'label' => LessonNotHeldCause::from($cause)->label(), 'count' => $count],
                array_keys($causes),
                $causes,
            ),
            'strike' => $strike,
            'teacher_absent' => $absent,
        ];
    }
}
