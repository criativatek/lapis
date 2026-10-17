<?php

namespace App\Services\Timetable;

use App\Models\AcademicYear;
use App\Models\TimetableActivity;
use App\Models\TimetableActivitySession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * O histórico das sessões das «outras atividades» de UM professor (0.170.0): só
 * as sessões COM registos (texto ou estado), filtradas por atividade e por
 * período. É a mesma consulta para o ecrã e para a exportação — um ficheiro
 * construído a partir de outra acabaria por discordar do ecrã.
 *
 * O filtro de atividade aceita o ulid de um bloco (aberto ou fechado) ou
 * `title:<designação>` para as sessões órfãs, que já não têm bloco e se
 * identificam pela cópia da designação.
 */
final class ActivitySessionHistory
{
    public const string ORPHAN_PREFIX = 'title:';

    /**
     * O período por omissão: o ano letivo de hoje (ou o mais recente que já
     * começou); `[null, null]` sem anos letivos.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function defaultPeriod(CarbonImmutable $today): array
    {
        $year = AcademicYear::containing($today->toDateString())
            ?? AcademicYear::query()->whereDate('starts_on', '<=', $today->toDateString())->orderByDesc('starts_on')->first();

        return $year === null
            ? [null, null]
            : [$year->starts_on->toDateString(), $year->ends_on->toDateString()];
    }

    /**
     * @return Builder<TimetableActivitySession>
     */
    public function query(User $teacher, ?string $activity, ?string $from, ?string $to): Builder
    {
        $query = TimetableActivitySession::query()
            ->where('user_id', $teacher->getKey())
            ->withRecords()
            ->with(['activity', 'schoolClass'])
            ->orderByDesc('occurs_on')
            ->orderByDesc('starts_at')
            ->orderByDesc('id');

        if ($from !== null) {
            $query->whereDate('occurs_on', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('occurs_on', '<=', $to);
        }

        if ($activity !== null && $activity !== '') {
            if (str_starts_with($activity, self::ORPHAN_PREFIX)) {
                $query->whereNull('timetable_activity_id')
                    ->where('activity_title', substr($activity, strlen(self::ORPHAN_PREFIX)));
            } else {
                // Uma atividade que não é deste professor nunca resolve: o
                // filtro fica vazio, e não confirma que ela existe.
                $id = TimetableActivity::query()
                    ->where('user_id', $teacher->getKey())
                    ->where('ulid', $activity)
                    ->value('id');
                $query->where('timetable_activity_id', $id ?? 0);
            }
        }

        return $query;
    }

    /**
     * As atividades que se podem escolher no filtro: todos os blocos do
     * professor (abertos ou fechados) e as designações das sessões órfãs.
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(User $teacher): array
    {
        $options = [];

        $activities = TimetableActivity::query()
            ->where('user_id', $teacher->getKey())
            ->orderBy('title')
            ->orderBy('id')
            ->get();

        foreach ($activities as $activity) {
            $options[] = [
                'value' => $activity->ulid,
                'label' => $activity->title.' · '.$activity->kind->label(),
            ];
        }

        $orphans = TimetableActivitySession::query()
            ->where('user_id', $teacher->getKey())
            ->whereNull('timetable_activity_id')
            ->withRecords()
            ->select('activity_title')
            ->distinct()
            ->orderBy('activity_title')
            ->get();

        foreach ($orphans as $orphan) {
            $options[] = [
                'value' => self::ORPHAN_PREFIX.$orphan->activity_title,
                'label' => $orphan->activity_title.' · atividade já sem horário',
            ];
        }

        return $options;
    }
}
