<?php

namespace App\Services\Lessons;

use App\Models\CalendarEvent;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Classes\ClassArchivalWindow;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Os «acontecimentos do dia» que a página de uma aula mostra ao lado do
 * sumário — os acontecimentos do Calendário do Ano Letivo que cobrem o DIA
 * LOCAL da aula e estão ligados à mesma turma.
 *
 * MESMO DIA LOCAL, NUNCA SOBREPOSIÇÃO DE HORAS: um acontecimento «todo o dia»
 * ou um cuja janela não toca a da aula conta na mesma, desde que o dia — lido
 * no fuso da ORGANIZAÇÃO da turma, a mesma fonte que ClassArchivalWindow já
 * usa — caia dentro de `starts_on`..`ends_on`.
 *
 * PESSOAL, E NÃO DA TURMA: um acontecimento é do professor que o escreveu
 * (CalendarEventPolicy), e não de quem quer que lecione a mesma turma. Um
 * colega de turma nunca vê aqui o que outro colega escreveu para si — só os
 * seus próprios acontecimentos, exatamente como o Calendário já impõe em
 * qualquer outro ecrã.
 */
final class LessonDayEvents
{
    public function __construct(private readonly ClassArchivalWindow $archivalWindow) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function for(Lesson $lesson, User $teacher): array
    {
        return $this->forLessons([$lesson], $teacher)[$lesson->getKey()] ?? [];
    }

    /**
     * Os acontecimentos de VÁRIAS aulas numa só consulta — a semana inteira, ou
     * a vista de uma turma ao longo de semanas —, em vez de uma por cartão. As
     * mesmas regras, o mesmo filtro e a mesma ordem que `for()`: aquela é este
     * método com uma aula.
     *
     * A consulta traz os acontecimentos do professor cuja janela de dias toca o
     * intervalo das aulas e que estão ligados a alguma das turmas; a atribuição
     * a cada aula (mesmo dia local E mesma turma) faz-se em memória, na ordem
     * que a base de dados já deu.
     *
     * @param  iterable<Lesson>  $lessons
     * @return array<int, list<array<string, mixed>>> indexado pelo id da aula; toda a aula tem entrada, vazia se não houver acontecimentos
     */
    public function forLessons(iterable $lessons, User $teacher): array
    {
        $collection = new EloquentCollection($lessons);
        $collection->loadMissing('schoolClass.organization');

        /** @var array<int, string> $dayByLesson */
        $dayByLesson = [];
        /** @var array<int, array{int, int}> $windowByLesson [início, fim] em minutos desde a meia-noite local */
        $windowByLesson = [];

        foreach ($collection as $lesson) {
            $timezone = $this->archivalWindow->timezoneFor($lesson->schoolClass);
            $localStart = $lesson->starts_at->clone()->setTimezone($timezone);
            $dayByLesson[(int) $lesson->getKey()] = $localStart->toDateString();
            $windowByLesson[(int) $lesson->getKey()] = $this->lessonWindow($localStart, $lesson->ends_at?->clone()->setTimezone($timezone));
        }

        $result = array_fill_keys(array_keys($dayByLesson), []);

        if ($dayByLesson === []) {
            return $result;
        }

        $firstDay = min($dayByLesson);
        $lastDay = max($dayByLesson);
        $classIds = array_values(array_unique($collection->pluck('class_id')->map(fn ($id): int => (int) $id)->all()));

        $events = CalendarEvent::query()
            ->where('user_id', $teacher->getKey())
            ->whereHas('schoolClasses', fn ($query) => $query->whereIn('classes.id', $classIds))
            ->with('schoolClasses:classes.id')
            ->whereDate('starts_on', '<=', $lastDay)
            ->whereDate('ends_on', '>=', $firstDay)
            // «Todo o dia» primeiro, depois por hora de início, depois por
            // título — uma ordem estável, e não a ordem de inserção na base
            // de dados, que a mesma turma no mesmo dia poderia embaralhar de
            // um pedido para o outro.
            ->orderByRaw('starts_at is not null')
            ->orderBy('starts_at')
            ->orderBy('title')
            ->get();

        foreach ($collection as $lesson) {
            $lessonId = (int) $lesson->getKey();
            $day = $dayByLesson[$lessonId];

            foreach ($events as $event) {
                if ($event->starts_on->toDateString() > $day || $event->ends_on->toDateString() < $day) {
                    continue;
                }

                if (! $event->schoolClasses->contains(fn (SchoolClass $schoolClass): bool => $schoolClass->getKey() === $lesson->class_id)) {
                    continue;
                }

                ['coincides' => $coincides, 'coverage' => $coverage] = $this->coincidence($event, $windowByLesson[$lessonId]);

                $result[$lessonId][] = [
                    'ulid' => $event->ulid,
                    'title' => $event->title,
                    'starts_at' => $event->starts_at === null ? null : substr($event->starts_at, 0, 5),
                    'ends_at' => $event->ends_at === null ? null : substr($event->ends_at, 0, 5),
                    'all_day' => $event->starts_at === null,
                    'notes' => $event->description,
                    'type_label' => $event->type->label(),
                    // Cruza as HORAS do acontecimento com as da aula (0.171.0).
                    // Só informa — quem decide o que fazer é o professor — e
                    // um acontecimento que não coincide continua na lista.
                    'coincides' => $coincides,
                    'coverage' => $coverage,
                ];
            }
        }

        return $result;
    }

    /**
     * O acontecimento COINCIDENTE com a aula que tem este ULID — ou NULL se não
     * existe, não é deste professor, não está ligado à turma da aula ou as
     * horas não se sobrepõem. É a validação de «Registar e adiar a preparação»:
     * o ULID vem do pedido e nunca se confia nele.
     */
    public function coincidingEvent(Lesson $lesson, User $teacher, string $ulid): ?CalendarEvent
    {
        $entry = collect($this->for($lesson, $teacher))
            ->first(fn (array $candidate): bool => $candidate['ulid'] === $ulid && $candidate['coincides'] === true);

        if ($entry === null) {
            return null;
        }

        return CalendarEvent::query()
            ->where('user_id', $teacher->getKey())
            ->where('ulid', $ulid)
            ->first();
    }

    /**
     * A linha que «Incluir no sumário» acrescenta para este acontecimento —
     * IGUAL à `summaryLineFor` de `resources/js/lib/lessonDayEvents.ts` (os
     * testes dos dois lados usam os mesmos casos): título e notas aparados,
     * quebras internas colapsadas num espaço, e `título — notas` quando há
     * notas.
     *
     * @param  CalendarEvent|array{title: string, notes?: string|null}  $event
     */
    public static function summaryLine(CalendarEvent|array $event): string
    {
        $title = $event instanceof CalendarEvent ? $event->title : $event['title'];
        $notes = $event instanceof CalendarEvent ? $event->description : ($event['notes'] ?? null);

        // `trim()` do PHP só apara ASCII; o do JavaScript apara também o espaço
        // inseparável e afins — a expressão com /u aproxima os dois.
        $singleLine = fn (string $text): string => (string) preg_replace('/\s*\n+\s*/u', ' ', (string) preg_replace('/^\s+|\s+$/u', '', $text));
        $title = $singleLine($title);
        $notes = $notes === null ? '' : $singleLine($notes);

        return $notes === '' ? $title : $title.' — '.$notes;
    }

    /**
     * A janela da aula em minutos locais. Sem fim conta como um minuto, para
     * uma aula sem hora de fim ainda ter uma janela que se pode sobrepor.
     *
     * @return array{int, int}
     */
    private function lessonWindow(CarbonInterface $start, ?CarbonInterface $end): array
    {
        $startMinute = $start->hour * 60 + $start->minute;

        if ($end === null) {
            return [$startMinute, $startMinute + 1];
        }

        // Um fim noutro dia (fuso da organização) fecha a janela à meia-noite.
        $endMinute = $end->toDateString() === $start->toDateString() ? $end->hour * 60 + $end->minute : 24 * 60;

        return [$startMinute, max($endMinute, $startMinute + 1)];
    }

    /**
     * Todo o dia coincide sempre; com horas, é a sobreposição estrita das duas
     * janelas — a janela diária do acontecimento (repetida em cada dia de um
     * acontecimento de vários dias) contra a da aula.
     *
     * @param  array{int, int}  $lessonWindow
     * @return array{coincides: bool, coverage: string|null}
     */
    private function coincidence(CalendarEvent $event, array $lessonWindow): array
    {
        if ($event->starts_at === null) {
            return ['coincides' => true, 'coverage' => 'all_day'];
        }

        [$lessonStart, $lessonEnd] = $lessonWindow;
        $eventStart = $this->minutesOf($event->starts_at);
        $eventEnd = $event->ends_at === null ? $eventStart + 1 : max($this->minutesOf($event->ends_at), $eventStart + 1);

        if (! ($eventStart < $lessonEnd && $lessonStart < $eventEnd)) {
            return ['coincides' => false, 'coverage' => null];
        }

        return [
            'coincides' => true,
            'coverage' => $eventStart <= $lessonStart && $eventEnd >= $lessonEnd ? 'whole_lesson' : 'partial',
        ];
    }

    private function minutesOf(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
