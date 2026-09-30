<?php

namespace App\Services\Lessons;

use App\Models\CalendarEvent;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Classes\ClassArchivalWindow;

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
        $lesson->loadMissing('schoolClass.organization');
        $timezone = $this->archivalWindow->timezoneFor($lesson->schoolClass);
        $day = $lesson->starts_at->clone()->setTimezone($timezone)->toDateString();

        return array_values(CalendarEvent::query()
            ->where('user_id', $teacher->getKey())
            ->whereHas('schoolClasses', fn ($query) => $query->whereKey($lesson->class_id))
            ->whereDate('starts_on', '<=', $day)
            ->whereDate('ends_on', '>=', $day)
            // «Todo o dia» primeiro, depois por hora de início, depois por
            // título — uma ordem estável, e não a ordem de inserção na base
            // de dados, que a mesma turma no mesmo dia poderia embaralhar de
            // um pedido para o outro.
            ->orderByRaw('starts_at is not null')
            ->orderBy('starts_at')
            ->orderBy('title')
            ->get()
            ->map(fn (CalendarEvent $event): array => [
                'ulid' => $event->ulid,
                'title' => $event->title,
                'starts_at' => $event->starts_at === null ? null : substr($event->starts_at, 0, 5),
                'ends_at' => $event->ends_at === null ? null : substr($event->ends_at, 0, 5),
                'all_day' => $event->starts_at === null,
                'notes' => $event->description,
                'type_label' => $event->type->label(),
            ])
            ->all());
    }
}
