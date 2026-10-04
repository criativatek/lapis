<?php

namespace App\Services\Lessons;

use App\Models\CalendarEvent;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Classes\ClassArchivalWindow;
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

        foreach ($collection as $lesson) {
            $timezone = $this->archivalWindow->timezoneFor($lesson->schoolClass);
            $dayByLesson[(int) $lesson->getKey()] = $lesson->starts_at->clone()->setTimezone($timezone)->toDateString();
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

                $result[$lessonId][] = [
                    'ulid' => $event->ulid,
                    'title' => $event->title,
                    'starts_at' => $event->starts_at === null ? null : substr($event->starts_at, 0, 5),
                    'ends_at' => $event->ends_at === null ? null : substr($event->ends_at, 0, 5),
                    'all_day' => $event->starts_at === null,
                    'notes' => $event->description,
                    'type_label' => $event->type->label(),
                ];
            }
        }

        return $result;
    }
}
