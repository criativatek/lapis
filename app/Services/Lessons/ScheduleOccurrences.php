<?php

namespace App\Services\Lessons;

use App\Models\AcademicCalendarException;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * «Quando é que esta turma tem aula, segundo o horário?»
 *
 * A pergunta que MaterializeLessonsForRange fazia em voz baixa dentro do seu
 * próprio ciclo — percorrer os tempos recorrentes, saltar os dias não letivos,
 * respeitar as janelas de vigência de cada tempo — e que passa a viver aqui
 * porque a inserção intermédia precisa exatamente da mesma resposta: as
 * PRÓXIMAS OCORRÊNCIAS VÁLIDAS do horário real, para lá deslocar as aulas que
 * vêm a seguir (§16).
 *
 * Extraída, e não copiada. Se «+1 dia» servisse, nada disto era preciso; mas
 * deslocar uma aula um dia para a frente põe a aula de segunda numa terça em
 * que a turma não tem aula nenhuma, e atropela o feriado que a materialização
 * teve o cuidado de saltar. Duas cópias desta regra divergiriam no primeiro
 * feriado que uma delas não conhecesse.
 */
final class ScheduleOccurrences
{
    private const TIMEZONE = 'Europe/Lisbon';

    /**
     * As ocorrências do horário desta turma entre duas datas, por ordem
     * cronológica.
     *
     * `$classGroupId` filtra por SEQUÊNCIA, não por tempo do horário: passar
     * NULL pede as ocorrências da turma inteira, e passar o id de um grupo pede
     * as desse grupo. Não são a mesma coisa nem se misturam — T1 e T2 avançam a
     * ritmos diferentes, e deslocar a sequência de T1 nunca pode ir buscar um
     * tempo de T2. `$anyAudience` desliga o filtro, para quem precisa do horário
     * completo da turma.
     *
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, slot_id: int, class_group_id: int|null}>
     */
    public function between(
        SchoolClass $class,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $classGroupId = null,
        bool $anyAudience = false,
    ): array {
        $from = $from->setTimezone(self::TIMEZONE)->startOfDay();
        $to = $to->setTimezone(self::TIMEZONE)->startOfDay();

        if ($from->greaterThan($to)) {
            return [];
        }

        $exceptions = $this->exceptionsFor($class, $from, $to);
        $occurrences = [];

        /** @var RecurringLessonSlot $slot */
        foreach ($class->recurringLessonSlots()->orderBy('id')->get() as $slot) {
            if (! $anyAudience && $slot->class_group_id !== $classGroupId) {
                continue;
            }

            $windowStart = $slot->starts_on === null
                ? $from
                : CarbonImmutable::parse($slot->starts_on, self::TIMEZONE)->startOfDay();
            $windowEnd = $slot->ends_on === null
                ? $to
                : CarbonImmutable::parse($slot->ends_on, self::TIMEZONE)->startOfDay();
            $start = $from->greaterThan($windowStart) ? $from : $windowStart;
            $end = $to->lessThan($windowEnd) ? $to : $windowEnd;

            if ($start->greaterThan($end)) {
                continue;
            }

            for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
                if ($date->dayOfWeekIso !== $slot->day_of_week) {
                    continue;
                }

                if ($this->isNonTeachingDay($date, $exceptions)) {
                    continue;
                }

                $occurrences[] = [
                    'starts_at' => CarbonImmutable::parse(
                        $date->toDateString().' '.$slot->starts_at,
                        self::TIMEZONE,
                    ),
                    'ends_at' => CarbonImmutable::parse(
                        $date->toDateString().' '.$slot->ends_at,
                        self::TIMEZONE,
                    ),
                    'slot_id' => (int) $slot->getKey(),
                    'class_group_id' => $slot->class_group_id === null ? null : (int) $slot->class_group_id,
                ];
            }
        }

        usort(
            $occurrences,
            fn (array $a, array $b): int => $a['starts_at']->getTimestamp() <=> $b['starts_at']->getTimestamp()
                ?: $a['slot_id'] <=> $b['slot_id'],
        );

        return $occurrences;
    }

    /**
     * As exceções letivas do ano DESTA turma que cruzam o intervalo.
     *
     * Ao próprio ano letivo, e nunca a uma tabela solta filtrada por datas: é o
     * que garante que o feriado do ano SEGUINTE, já lançado na mesma
     * organização, não apaga a aula de hoje. `whereDate` dos dois lados porque
     * são colunas `date` guardadas como «Y-m-d 00:00:00» — comparadas como texto
     * contra um limite «Y-m-d», a exceção do último dia do intervalo
     * desaparecia sem erro nenhum.
     *
     * @return Collection<int, AcademicCalendarException>
     */
    private function exceptionsFor(
        SchoolClass $class,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Collection {
        $academicYear = $class->academicYear()->first();

        if ($academicYear === null) {
            return collect();
        }

        return $academicYear->exceptions()
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->get();
    }

    /**
     * Inclusiva dos dois lados — `starts_on <= data <= ends_on` —, a mesma
     * inclusividade com que AcademicYearCalendarQuery trata os limites de um
     * período. Comparada ao dia, e nunca por instantes: o que estas colunas
     * dizem é um DIA.
     *
     * @param  Collection<int, AcademicCalendarException>  $exceptions
     */
    private function isNonTeachingDay(CarbonImmutable $date, Collection $exceptions): bool
    {
        $day = $date->toDateString();

        return $exceptions->contains(
            fn (AcademicCalendarException $exception): bool => $exception->starts_on->toDateString() <= $day
                && $exception->ends_on->toDateString() >= $day,
        );
    }
}
