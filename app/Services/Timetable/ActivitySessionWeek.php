<?php

namespace App\Services\Timetable;

use App\Models\TimetableActivitySession;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * As sessões das «outras atividades» de um professor entre duas datas (0.170.0):
 * as ocorrências virtuais dos seus blocos e as sessões que já existem — as que
 * ficaram fora do horário atual incluídas.
 *
 * SÓ LEITURA. Nada aqui cria uma sessão: uma ocorrência que ninguém registou é
 * apenas uma linha desta lista.
 *
 * Quando um bloco tem sessão nessa data, a sessão ocupa o lugar da ocorrência
 * (uma só linha por atividade e data, nunca duas). Uma sessão que já não bate com
 * nenhuma ocorrência — porque o bloco mudou de dia ou foi fechado — aparece na
 * mesma, na sua data e hora, identificada pela cópia.
 *
 * É a fonte da prop `activitySessions` de «Aulas e Sumários» e do Horário.
 * Nunca se mistura com `lessons`: as aulas continuam a ser só aulas.
 */
final class ActivitySessionWeek
{
    public function __construct(
        private readonly ActivityOccurrences $occurrences,
        private readonly ActivitySessionPresenter $presenter,
    ) {}

    /**
     * Ordenadas por início, depois por título e por chave — o servidor
     * desempata para a ordem ser estável.
     *
     * @param  bool  $hideNonTeachingDays  esconde as ocorrências virtuais de dias não letivos (as sessões existentes ficam sempre)
     * @return list<array<string, mixed>>
     */
    public function between(User $teacher, CarbonImmutable $from, CarbonImmutable $to, bool $hideNonTeachingDays = true): array
    {
        $sessions = TimetableActivitySession::query()
            ->where('user_id', $teacher->getKey())
            ->whereDate('occurs_on', '>=', $from->toDateString())
            ->whereDate('occurs_on', '<=', $to->toDateString())
            ->with(['activity', 'schoolClass'])
            ->get();

        $byOccurrence = $sessions
            ->filter(fn (TimetableActivitySession $session): bool => $session->timetable_activity_id !== null)
            ->keyBy(fn (TimetableActivitySession $session): string => $session->timetable_activity_id.'|'.$session->occurs_on->toDateString());

        $items = [];
        $consumed = [];

        foreach ($this->occurrences->between($teacher, $from, $to) as $occurrence) {
            $key = $occurrence['activity']->getKey().'|'.$occurrence['date'];
            $session = $byOccurrence->get($key);

            if ($session === null && $hideNonTeachingDays && ! $occurrence['registrable']) {
                continue;
            }

            if ($session !== null) {
                $consumed[$session->getKey()] = true;
            }

            $items[] = $this->presenter->occurrence($occurrence['activity'], $occurrence['date'], $session, $occurrence['registrable']);
        }

        foreach ($sessions as $session) {
            if (! isset($consumed[$session->getKey()])) {
                $items[] = $this->presenter->session($session);
            }
        }

        usort(
            $items,
            fn (array $a, array $b): int => [$a['occurs_on'], $a['start_time'], $a['end_time'], $a['title'], $a['key']] <=> [$b['occurs_on'], $b['start_time'], $b['end_time'], $b['title'], $b['key']],
        );

        return $items;
    }
}
