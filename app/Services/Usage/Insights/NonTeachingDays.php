<?php

namespace App\Services\Usage\Insights;

use App\Models\AcademicCalendarExceptionType;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Os dias não letivos de cada pessoa: feriados, interrupções letivas e dias
 * não letivos registados no calendário das organizações de que é membro.
 *
 * Um dia é não letivo se o for em QUALQUER das organizações da pessoa — é a
 * leitura que gera menos sinais. Lê `academic_calendar_exceptions` com a
 * query builder: é um aggregate entre organizações, não há tenant a resolver.
 *
 * Calendário, não conteúdo pedagógico: é a única tabela «de dados da escola»
 * que o painel lê, e só para saber que dias não contam para um prazo.
 */
final class NonTeachingDays
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, true>> user_id => conjunto de `Y-m-d` não letivos
     */
    public function forUsers(array $userIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $result = array_fill_keys($userIds, []);

        if ($userIds === []) {
            return $result;
        }

        $organizationsOf = [];
        foreach (array_chunk($userIds, 500) as $chunk) {
            foreach (DB::table('organization_memberships')->whereIn('user_id', $chunk)->get(['user_id', 'organization_id']) as $row) {
                $organizationsOf[(int) $row->organization_id][] = (int) $row->user_id;
            }
        }

        if ($organizationsOf === []) {
            return $result;
        }

        $fromDay = UsageDates::iso($from);
        $toDay = UsageDates::iso($to);

        foreach (array_chunk(array_keys($organizationsOf), 500) as $organizationChunk) {
            $exceptions = DB::table('academic_calendar_exceptions')
                ->whereIn('organization_id', $organizationChunk)
                ->whereIn('type', AcademicCalendarExceptionType::values())
                ->where('ends_on', '>=', $fromDay)
                ->where('starts_on', '<=', $toDay)
                ->get(['organization_id', 'starts_on', 'ends_on']);

            foreach ($exceptions as $exception) {
                $start = max(substr((string) $exception->starts_on, 0, 10), $fromDay);
                $end = min(substr((string) $exception->ends_on, 0, 10), $toDay);

                foreach (UsageDates::eachDay(UsageDates::day($start), UsageDates::day($end)) as $day) {
                    $iso = UsageDates::iso($day);

                    foreach ($organizationsOf[(int) $exception->organization_id] as $userId) {
                        $result[$userId][$iso] = true;
                    }
                }
            }
        }

        return $result;
    }
}
