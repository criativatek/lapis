<?php

namespace App\Actions\Lessons;

use App\Models\AcademicCalendarException;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * O ÚNICO SÍTIO ONDE UMA AULA NASCE. MaterializeLessonsForWeek é uma casca fina
 * por cima disto — chama este `execute()` uma vez por turma —, e por isso é aqui
 * dentro, e em mais lado nenhum, que vive a regra de que num feriado não há aula
 * (Fase 5.5): posta aqui, aplica-se a todos os caminhos de materialização de uma
 * só vez, incluindo os que ainda não existem. Numa camada de interface
 * aplicar-se-ia só ao ecrã que a tivesse.
 */
class MaterializeLessonsForRange
{
    private const TIMEZONE = 'Europe/Lisbon';

    /**
     * @return Collection<int, Lesson>
     */
    public function execute(
        SchoolClass $class,
        CarbonImmutable $from,
        CarbonImmutable $to,
        User $actor,
    ): Collection {
        $from = $from->setTimezone(self::TIMEZONE)->startOfDay();
        $to = $to->setTimezone(self::TIMEZONE)->startOfDay();

        return DB::transaction(function () use ($class, $from, $to, $actor): Collection {
            $lockedClass = SchoolClass::query()
                ->whereKey($class->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $academicYear = $lockedClass->academicYear()->firstOrFail();
            $academicYearStartsOn = CarbonImmutable::parse($academicYear->starts_on, self::TIMEZONE)->startOfDay();
            $academicYearEndsOn = CarbonImmutable::parse($academicYear->ends_on, self::TIMEZONE)->startOfDay();

            if ($from->greaterThan($to)
                || $from->lessThan($academicYearStartsOn)
                || $to->greaterThan($academicYearEndsOn)) {
                throw ValidationException::withMessages([
                    'range' => __('O intervalo tem de ficar dentro do ano letivo da turma.'),
                ]);
            }

            $lessons = collect();

            // As exceções letivas DESTE ano que cruzam o intervalo — os feriados,
            // as interrupções letivas e os dias não letivos (Fase 5.4). Lidas UMA
            // vez, aqui fora, e nunca uma consulta por cada data candidata lá
            // dentro: a janela de um professor são semanas, e as exceções que a
            // cruzam contam-se pelos dedos, pelo que a varredura linear em memória
            // é mais barata do que a ida à base de dados que a substituiria.
            //
            // Ao PRÓPRIO ano (`$academicYear->exceptions()`) e nunca a uma tabela
            // solta filtrada por datas: é a mesma pergunta, feita da mesma maneira,
            // que AcademicYearCalendarQuery já faz. É também o que garante que o
            // feriado do ANO SEGUINTE — já lançado, na mesma organização — não
            // impede a aula de hoje. A fronteira da organização não é repetida por
            // cima disto porque o global scope do próprio modelo já a desenha, tal
            // como para o AcademicPeriod e o CalendarEvent.
            //
            // `whereDate` nos dois lados, e nunca um `where` simples: são colunas
            // `date` guardadas como «Y-m-d 00:00:00», e, comparadas como texto contra
            // um limite «Y-m-d», a exceção do último dia do intervalo desaparecia sem
            // erro nenhum — a armadilha que já mordeu os períodos e os acontecimentos.
            $exceptions = $academicYear->exceptions()
                ->whereDate('starts_on', '<=', $to->toDateString())
                ->whereDate('ends_on', '>=', $from->toDateString())
                ->get();

            /** @var RecurringLessonSlot $slot */
            foreach ($lockedClass->recurringLessonSlots()->orderBy('id')->get() as $slot) {
                $slotStartsOn = $slot->starts_on === null
                    ? $from
                    : CarbonImmutable::parse($slot->starts_on, self::TIMEZONE)->startOfDay();
                $slotEndsOn = $slot->ends_on === null
                    ? $to
                    : CarbonImmutable::parse($slot->ends_on, self::TIMEZONE)->startOfDay();
                $occurrenceStartsOn = $from->greaterThan($slotStartsOn) ? $from : $slotStartsOn;
                $occurrenceEndsOn = $to->lessThan($slotEndsOn) ? $to : $slotEndsOn;

                if ($occurrenceStartsOn->greaterThan($occurrenceEndsOn)) {
                    continue;
                }

                for ($date = $occurrenceStartsOn; $date->lessThanOrEqualTo($occurrenceEndsOn); $date = $date->addDay()) {
                    if ($date->dayOfWeekIso !== $slot->day_of_week) {
                        continue;
                    }

                    // Feriado, interrupção letiva ou dia não letivo: naquele dia
                    // não há aula, e por isso nenhuma é criada. AS TRÊS ESPÉCIES
                    // BLOQUEIAM IGUAL — a materialização não pergunta que espécie
                    // de dia não letivo é, só se é um.
                    //
                    // E O SLOT NÃO É TOCADO. A rotina («Português às quintas»)
                    // sobrevive intacta ao feriado: o que não acontece é aquela
                    // ocorrência, e não a rotina. Materializar o mesmo intervalo
                    // outra vez continua a criar todas as outras quintas-feiras.
                    //
                    // NADA DO QUE JÁ EXISTE É REVISTO AQUI. Isto impede a criação
                    // de uma aula NOVA e mais nada: uma aula que já exista nesta
                    // data — porque a exceção foi lançada depois, por exemplo, e já
                    // leva sumário ou estado — não é apagada nem alterada por esta
                    // passagem, nem por nenhuma outra.
                    if ($this->isNonTeachingDay($date, $exceptions)) {
                        continue;
                    }

                    $startsAt = CarbonImmutable::parse(
                        $date->toDateString().' '.$slot->starts_at,
                        self::TIMEZONE,
                    );
                    $endsAt = CarbonImmutable::parse(
                        $date->toDateString().' '.$slot->ends_at,
                        self::TIMEZONE,
                    );

                    // O GRUPO É COPIADO PARA A AULA, e vai nos atributos de
                    // CRIAÇÃO — nunca na chave do firstOrCreate. A identidade
                    // de uma aula continua a ser (turma, tempo do horário,
                    // início): pôr o grupo na chave faria uma revisão do slot
                    // criar uma segunda aula por cima da que já existe naquele
                    // instante, que é exatamente a duplicação que a chave
                    // `lessons_class_slot_start_unique` existe para impedir.
                    //
                    // E porque está nos atributos de criação, e não nos de
                    // atualização, uma aula que JÁ exista fica com o grupo com
                    // que nasceu ainda que o slot tenha mudado entretanto. É o
                    // que faz do instantâneo um instantâneo.
                    $lessons->push(Lesson::query()->firstOrCreate(
                        [
                            'class_id' => $lockedClass->id,
                            'recurring_lesson_slot_id' => $slot->id,
                            'starts_at' => $startsAt,
                        ],
                        [
                            'class_group_id' => $slot->class_group_id,
                            'ends_at' => $endsAt,
                            'status' => LessonStatus::Preparation,
                            'created_by' => $actor->id,
                        ],
                    ));
                }
            }

            return $lessons->sortBy('starts_at')->values();
        });
    }

    /**
     * A data cai dentro de alguma das exceções já lidas?
     *
     * INCLUSIVA DOS DOIS LADOS — `starts_on <= data <= ends_on` —, o mesmo idioma
     * e a mesma inclusividade com que AcademicYearCalendarQuery já trata os limites
     * de um AcademicPeriod. O primeiro e o último dia de uma interrupção letiva são
     * dela; o dia antes e o dia depois não são, e nesses a aula é criada como
     * sempre foi.
     *
     * Comparada ao dia, por «Y-m-d», e não por instantes: `starts_on` e `ends_on`
     * são colunas `date` — o que elas dizem é um DIA, e nunca um momento —, e
     * comparar meias-noites de fusos que não têm de coincidir tornaria a resposta
     * dependente da hora legal em vigor naquele dia.
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
