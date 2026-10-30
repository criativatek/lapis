<?php

namespace App\Services\Lessons;

use App\Actions\Lessons\CalendarReconciliationPlan;
use App\Models\AcademicCalendarException;
use App\Models\Lesson;
use App\Models\SchoolClass;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A forma JSON de um plano de reconciliação com o calendário
 * (CalendarReconciliationPlan) e das aulas em conflito — o contrato que o
 * responsável e o professor leem.
 *
 * SÓ FACTOS, NUNCA CONTEÚDO. Nada daqui devolve o texto de um sumário, de um
 * plano ou de uma nota privada: o plano da ação já só traz ulids, datas, classes
 * (A/B/C), motivos em código e os NOMES dos campos que viajam. Também não sai
 * nenhum id interno — as turmas e as aulas identificam-se pelo ulid.
 *
 * Duas vistas do mesmo plano: `owner()` (contagens por turma, sem aulas — é o
 * que o responsável vê) e `teacher()` (o detalhe aula a aula das turmas do
 * próprio professor). `result()` é o resumo depois de executar.
 */
final class CalendarReconciliationPresenter
{
    private const TIMEZONE = 'Europe/Lisbon';

    /** @var array<string, string> */
    private const REASONS = [
        'archived_class' => 'A turma está arquivada.',
        'manual_origin' => 'Aula criada à mão.',
        'taught' => 'Aula já lecionada.',
        'closed' => 'Aula já fechada.',
        'manual_number' => 'Tem um número de lição indicado à mão.',
        'legacy_number_end' => 'Corresponde a mais do que uma lição.',
        'attendance_recorded' => 'Tem assiduidade registada.',
        'attendance_draft' => 'Tem faltas em rascunho.',
        'pending_plan' => 'Tem um planeamento pendente.',
        'reviewed_summary' => 'O sumário já foi revisto.',
        'sequence_provenance' => 'Vem de uma sequência de lições.',
        'closed_lesson_after' => 'Há uma aula já fechada depois desta.',
        'shared_unit' => 'Os grupos da turma (T1/T2) dão esta lição em aulas separadas, e a do outro grupo continua a existir: esta fica como está, para a lição não se partir.',
        'no_destination' => 'Não há aula livre para onde levar o planeamento.',
        'protected_lesson_after' => 'O planeamento teria de passar por uma aula seguinte que já tem registos, que já passou ou que fica num dia sem aulas.',
        'batch_limit' => 'Fica para a correção seguinte: há demasiadas aulas com planeamento para uma só vez.',
    ];

    /**
     * As mesmas razões, como continuação de uma frase — «7.º A: 1 aula (já foi
     * lecionada)» —, para a contagem por razão de «Precisa de revisão». Sem
     * tratamento (nem «tu» nem «você»): servem ao responsável e ao professor.
     *
     * @var array<string, string>
     */
    private const REASON_PHRASES = [
        'archived_class' => 'a turma está arquivada',
        'manual_origin' => 'foi criada à mão',
        'taught' => 'já foi lecionada',
        'closed' => 'já foi fechada',
        'manual_number' => 'tem um número de lição indicado à mão',
        'legacy_number_end' => 'corresponde a mais do que uma lição',
        'attendance_recorded' => 'tem assiduidade registada',
        'attendance_draft' => 'tem faltas em rascunho',
        'pending_plan' => 'tem um planeamento pendente',
        'reviewed_summary' => 'o sumário já foi revisto',
        'sequence_provenance' => 'vem de uma sequência de lições',
        'closed_lesson_after' => 'há uma aula já fechada depois dela',
        'no_destination' => 'não há aula livre para onde levar o planeamento',
        'protected_lesson_after' => 'o planeamento teria de passar por uma aula que já tem registos, que já passou ou que fica num dia sem aulas',
        'batch_limit' => 'fica para a correção seguinte',
    ];

    /**
     * «Precisa de revisão», por razão: `[{count, label}]` de uma turma, para a
     * frase «7.º A: 1 aula (faz parte de uma lição partilhada com o grupo T2,
     * que continua a ter aula)». Só razões e nomes de grupos.
     *
     * @param  list<array{reasons: list<string>, partners: list<string>, count: int}>  $entries
     * @return list<array{count: int, label: string}>
     */
    public function reviewBreakdown(array $entries): array
    {
        return array_map(function (array $entry): array {
            $parts = [];

            foreach ($entry['reasons'] as $code) {
                if ($code === 'shared_unit') {
                    $partners = $entry['partners'];
                    $parts[] = match (count($partners)) {
                        0 => __('faz parte de uma lição partilhada com outro grupo, que continua a ter aula'),
                        1 => __('faz parte de uma lição partilhada com o grupo :group, que continua a ter aula', ['group' => $partners[0]]),
                        default => __('faz parte de uma lição partilhada com os grupos :groups, que continuam a ter aula', [
                            'groups' => implode(', ', array_slice($partners, 0, -1)).' e '.$partners[count($partners) - 1],
                        ]),
                    };

                    continue;
                }

                $parts[] = isset(self::REASON_PHRASES[$code]) ? __(self::REASON_PHRASES[$code]) : $code;
            }

            return ['count' => $entry['count'], 'label' => implode('; ', $parts)];
        }, $entries);
    }

    /**
     * @return array{code: string, label: string}
     */
    public function reason(string $code): array
    {
        return ['code' => $code, 'label' => isset(self::REASONS[$code]) ? __(self::REASONS[$code]) : $code];
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{code: string, label: string}>
     */
    public function reasons(array $codes): array
    {
        return array_map(fn (string $code): array => $this->reason($code), $codes);
    }

    /**
     * A vista do responsável: contagens por turma, lacunas e dias libertados.
     *
     * @return array<string, mixed>
     */
    public function owner(CalendarReconciliationPlan $plan): array
    {
        return $this->base($plan);
    }

    /**
     * O resumo depois de executar: as mesmas contagens, sem detalhe de aulas.
     *
     * @return array<string, mixed>
     */
    public function result(CalendarReconciliationPlan $plan): array
    {
        return $this->base($plan);
    }

    /**
     * A vista do professor: o detalhe aula a aula das suas turmas.
     *
     * @param  Collection<int, AcademicCalendarException>  $exceptions  as exceções do ano, para os títulos
     * @return array<string, mixed>
     */
    public function teacher(CalendarReconciliationPlan $plan, Collection $exceptions): array
    {
        $payload = $this->base($plan);
        $ulids = [];

        foreach ($plan->classes as $class) {
            foreach ($class['lessons'] ?? [] as $row) {
                $ulids[] = $row['ulid'];
            }
        }

        $lessons = $ulids === []
            ? collect()
            : Lesson::query()->whereIn('ulid', array_unique($ulids))->with('classGroup')->get()->keyBy('ulid');
        $classes = $this->classesById(array_column($plan->classes, 'class_id'));

        $payload['classes'] = array_map(fn (array $class): array => $this->classSummary($class, $classes) + [
            // ONDE FICA CADA PLANEAMENTO, no fim: o das aulas retiradas e,
            // à parte, o que já estava nas aulas seguintes e avança.
            'planning' => array_map(fn (array $item): array => [
                'origin' => $item['origin'],
                'from_date' => CarbonImmutable::parse($item['from_starts_at'])->setTimezone(self::TIMEZONE)->toDateString(),
                'to_date' => $item['to_starts_at'] === null ? null : CarbonImmutable::parse($item['to_starts_at'])->setTimezone(self::TIMEZONE)->toDateString(),
                'new_lesson' => $item['new_lesson'],
                'pending' => $item['pending'],
            ], $class['planning'] ?? []),
            'lessons' => array_map(function (array $row) use ($lessons, $exceptions): array {
                /** @var Lesson|null $lesson */
                $lesson = $lessons->get($row['ulid']);
                $startsAt = CarbonImmutable::parse($row['starts_at'])->setTimezone(self::TIMEZONE);

                return [
                    'ulid' => $row['ulid'],
                    'date' => $startsAt->toDateString(),
                    'time' => $startsAt->format('H:i'),
                    'group' => $lesson?->classGroup?->label,
                    'kind' => $row['kind'],
                    'retired' => $row['retired'],
                    'reasons' => $this->reasons($row['reasons']),
                    'exception_titles' => $this->titlesCovering($exceptions, $startsAt->toDateString()),
                    'moves' => array_map(fn (array $move): array => [
                        // NULL quando o destino é uma aula que o plano cria a
                        // partir do horário (`new_lesson`): na pré-visualização
                        // ela ainda não existe, e não há para onde ligar.
                        'to_lesson' => $move['to'],
                        'to_date' => CarbonImmutable::parse($move['to_starts_at'])->setTimezone(self::TIMEZONE)->toDateString(),
                        'to_time' => CarbonImmutable::parse($move['to_starts_at'])->setTimezone(self::TIMEZONE)->format('H:i'),
                        'new_lesson' => $move['created_from_timetable'],
                        'fields' => $move['fields'],
                    ], $row['moves']),
                    'pending' => $row['pending'],
                    'pending_on_lesson' => $row['pending_on'],
                ];
            }, $class['lessons'] ?? []),
        ], $plan->classes);

        $payload['existing_on_freed_days'] = [
            'count' => $plan->existingOnFreedDays['count'],
            'lessons' => array_map(fn (array $row): array => [
                'ulid' => $row['ulid'],
                'starts_at' => $row['starts_at'],
                'closed' => $row['closed'],
            ], $plan->existingOnFreedDays['lessons'] ?? []),
        ];

        return $payload;
    }

    /**
     * Os títulos das exceções que cobrem um dia.
     *
     * @param  Collection<int, AcademicCalendarException>  $exceptions
     * @return list<string>
     */
    public function titlesCovering(Collection $exceptions, string $day): array
    {
        return array_values($exceptions
            ->filter(fn (AcademicCalendarException $exception): bool => $exception->starts_on->toDateString() <= $day
                && $exception->ends_on->toDateString() >= $day)
            ->map(fn (AcademicCalendarException $exception): string => $exception->title)
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function base(CalendarReconciliationPlan $plan): array
    {
        $classes = $this->classesById(array_merge(array_column($plan->classes, 'class_id'), array_keys($plan->gaps)));

        return [
            'plan_token' => $plan->token,
            'scope' => $plan->scopeKind,
            'operation' => $plan->operation,
            // A (retiradas), B (planeamento) e C (revisão individual).
            'counts' => [
                'A' => $plan->totals['retired'],
                'B' => $plan->totals['shifted'] + $plan->totals['awaiting_teacher'],
                'C' => $plan->totals['flagged'],
            ],
            'totals' => $plan->totals,
            'classes' => array_map(fn (array $class): array => $this->classSummary($class, $classes), $plan->classes),
            'gaps' => array_map(fn (int $classId, array $numbers): array => [
                'class' => $classes[$classId]['ulid'] ?? null,
                'class_label' => $classes[$classId]['label'] ?? '',
                'numbers' => $numbers,
            ], array_keys($plan->gaps), array_values($plan->gaps)),
            'freed_days' => $plan->freedDays,
            'existing_on_freed_days' => ['count' => $plan->existingOnFreedDays['count']],
            'changes_anything' => $plan->changesAnything(),
        ];
    }

    /**
     * @param  array<string, mixed>  $class
     * @param  array<int, array{ulid: string, label: string}>  $classes
     * @return array<string, mixed>
     */
    private function classSummary(array $class, array $classes): array
    {
        return [
            'class' => $classes[(int) $class['class_id']]['ulid'] ?? null,
            'class_label' => $class['class_label'],
            'retired' => $class['retired'],
            'shifted' => $class['shifted'],
            'awaiting_teacher' => $class['awaiting_teacher'],
            'flagged' => $class['flagged'],
            'renumbered' => $class['renumbered'],
            'gaps' => $class['gaps'],
            'review' => $this->reviewBreakdown($class['review'] ?? []),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{ulid: string, label: string}>
     */
    private function classesById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $result = [];

        foreach (SchoolClass::query()->whereIn('id', array_unique($ids))->get(['id', 'ulid', 'label']) as $class) {
            $result[(int) $class->getKey()] = ['ulid' => $class->ulid, 'label' => $class->label];
        }

        return $result;
    }
}
