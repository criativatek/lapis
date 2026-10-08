<?php

namespace App\Services\Lessons;

use App\Models\AcademicYear;
use App\Models\CancelledLessonOccurrence;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Classes\ClassArchivalWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * «Qual é o próximo dia em que este professor dá aula?» — SÓ LEITURA.
 *
 * Serve o atalho «Aulas de hoje» do Dashboard: quando o dia de hoje (ou o resto
 * da semana aberta) não tem aulas, a página precisa de saber para que semana
 * saltar. A resposta tem de olhar para DUAS fontes, porque as aulas de semanas
 * futuras só passam a existir quando alguém abre essa semana
 * (MaterializeLessonsForRange):
 *
 *  - as aulas que já existem, lidas pelo MESMO construtor da semana
 *    (LessonRowBuilder::rows), para que a regra do arquivamento — uma
 *    ocorrência vazia de uma turma arquivada, depois do arquivamento, não conta
 *    — seja a que a semana aplica, e não uma cópia;
 *  - as ocorrências do horário ainda por materializar (ScheduleOccurrences),
 *    menos as eliminadas de propósito (CancelledLessonOccurrence) e as que caem
 *    depois do arquivamento (ClassArchivalWindow::lastScheduledDay). Os dias
 *    não letivos já são saltados por ScheduleOccurrences.
 *
 * Nada aqui cria, altera ou materializa uma aula: abrir a semana de destino é
 * que materializa, como sempre.
 */
final class NextLessonDay
{
    /** Aulas lidas de cada vez: a primeira linha visível quase sempre está no primeiro lote. */
    private const BATCH = 50;

    public function __construct(
        private readonly LessonRowBuilder $rowBuilder,
        private readonly ScheduleOccurrences $occurrences,
        private readonly ClassArchivalWindow $archivalWindow,
    ) {}

    /**
     * O primeiro dia local ESTRITAMENTE posterior a `$after`, e nunca anterior a
     * `$today`, com aula neste ano letivo — «Y-m-d» — ou `null`.
     *
     * @param  list<string>  $classUlids  vazio = todas as turmas do professor; ULIDs que ele não leciona são ignorados
     */
    public function after(
        User $teacher,
        AcademicYear $academicYear,
        CarbonImmutable $after,
        CarbonImmutable $today,
        array $classUlids = [],
    ): ?string {
        $timezone = (string) config('app.timezone');
        $from = $after->setTimezone($timezone)->startOfDay()->addDay();
        $todayStart = $today->setTimezone($timezone)->startOfDay();
        $yearStart = CarbonImmutable::parse($academicYear->starts_on, $timezone)->startOfDay();
        $yearEnd = CarbonImmutable::parse($academicYear->ends_on, $timezone)->startOfDay();

        foreach ([$todayStart, $yearStart] as $floor) {
            if ($floor->greaterThan($from)) {
                $from = $floor;
            }
        }

        if ($from->greaterThan($yearEnd)) {
            return null;
        }

        /** @var Collection<int, SchoolClass> $classes */
        $classes = SchoolClass::query()
            ->where('academic_year_id', $academicYear->getKey())
            ->taughtBy($teacher)
            ->when($classUlids !== [], fn ($query) => $query->whereIn('ulid', $classUlids))
            ->get();

        if ($classes->isEmpty()) {
            return null;
        }

        $existing = $this->firstExistingDay($teacher, $academicYear, $classes, $from, $yearEnd, $timezone);

        // A procura no horário nunca vai além do que as aulas existentes já
        // garantem: só interessa um dia ANTERIOR.
        $limit = $existing !== null ? CarbonImmutable::parse($existing, $timezone)->startOfDay() : $yearEnd;
        $scheduled = $this->firstScheduledDay($classes, $from, $limit);

        $candidates = array_filter([$existing, $scheduled]);

        return $candidates === [] ? null : min($candidates);
    }

    /**
     * Em lotes e por ordem cronológica, até à primeira aula que a semana
     * mostraria: as escondidas pelo arquivamento não contam.
     *
     * @param  Collection<int, SchoolClass>  $classes
     */
    private function firstExistingDay(
        User $teacher,
        AcademicYear $academicYear,
        Collection $classes,
        CarbonImmutable $from,
        CarbonImmutable $yearEnd,
        string $timezone,
    ): ?string {
        $classIds = $classes->pluck('id')->all();
        $until = $yearEnd->endOfDay();
        $lastStart = null;
        $lastId = null;

        while (true) {
            $lessons = $this->rowBuilder->query($teacher, $academicYear)
                ->whereIn('class_id', $classIds)
                ->where('starts_at', '>=', $from)
                ->where('starts_at', '<=', $until)
                ->when($lastStart !== null, fn ($query) => $query->where(
                    fn ($tuple) => $tuple
                        ->where('starts_at', '>', $lastStart)
                        ->orWhere(fn ($tie) => $tie->where('starts_at', $lastStart)->where('id', '>', $lastId)),
                ))
                ->orderBy('starts_at')
                ->orderBy('id')
                ->limit(self::BATCH)
                ->get();

            if ($lessons->isEmpty()) {
                return null;
            }

            $rows = $this->rowBuilder->rows($lessons, $teacher);

            if ($rows !== []) {
                return CarbonImmutable::parse((string) $rows[0]['starts_at'])->setTimezone($timezone)->toDateString();
            }

            /** @var Lesson $last */
            $last = $lessons->last();
            $lastStart = $last->starts_at;
            $lastId = $last->getKey();

            if ($lessons->count() < self::BATCH) {
                return null;
            }
        }
    }

    /**
     * @param  Collection<int, SchoolClass>  $classes
     */
    private function firstScheduledDay(Collection $classes, CarbonImmutable $from, CarbonImmutable $limit): ?string
    {
        $found = null;

        foreach ($classes as $class) {
            $end = $found !== null ? CarbonImmutable::parse($found, $from->getTimezone())->startOfDay() : $limit;
            $lastScheduledDay = $this->archivalWindow->lastScheduledDay($class);

            if ($lastScheduledDay !== null && $lastScheduledDay->lessThan($end)) {
                $end = $lastScheduledDay;
            }

            if ($from->greaterThan($end)) {
                continue;
            }

            $cancelled = CancelledLessonOccurrence::query()
                ->where('class_id', $class->getKey())
                ->whereBetween('occurs_at', [$from->startOfDay(), $end->endOfDay()])
                ->get()
                ->map(fn (CancelledLessonOccurrence $occurrence): string => $occurrence->recurring_lesson_slot_id
                    .'@'.$occurrence->occurs_at->format('Y-m-d H:i:s'))
                ->flip();

            foreach ($this->occurrences->between($class, $from, $end, anyAudience: true) as $occurrence) {
                if ($cancelled->has($occurrence['slot_id'].'@'.$occurrence['starts_at']->format('Y-m-d H:i:s'))) {
                    continue;
                }

                $day = $occurrence['starts_at']->toDateString();

                if ($found === null || $day < $found) {
                    $found = $day;
                }

                // Ordenadas: a primeira não cancelada é a desta turma.
                break;
            }
        }

        return $found;
    }
}
