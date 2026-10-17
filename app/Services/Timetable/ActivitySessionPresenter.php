<?php

namespace App\Services\Timetable;

use App\Models\TimetableActivity;
use App\Models\TimetableActivityKind;
use App\Models\TimetableActivitySession;
use App\Models\TimetableActivitySessionStatus;
use Carbon\CarbonImmutable;

/**
 * A forma, para o front, de uma sessão de uma «outra atividade» — existente ou
 * ainda virtual (a ocorrência do bloco que ninguém registou). O Horário, «Aulas
 * e Sumários» e o histórico leem todos desta forma, e por isso dizem sempre o
 * mesmo.
 *
 * Quando a sessão existe, a identificação (título, tipo, turma, horas) é a
 * COPIA guardada nela, e nunca a do bloco atual: é isso que a mantém legível se
 * o bloco mudar.
 */
final class ActivitySessionPresenter
{
    private const TIMEZONE = 'Europe/Lisbon';

    /**
     * Uma ocorrência do bloco numa data, com a sessão dessa data se existir.
     *
     * @return array<string, mixed>
     */
    public function occurrence(TimetableActivity $activity, string $date, ?TimetableActivitySession $session, bool $registrable): array
    {
        return $session === null
            ? $this->virtual($activity, $date, $registrable)
            : $this->session($session, $activity);
    }

    /**
     * Uma sessão existente. `$activity` é o bloco atual, quando a sessão ainda
     * tem um (e `null` quando ficou órfã).
     *
     * @return array<string, mixed>
     */
    public function session(TimetableActivitySession $session, ?TimetableActivity $activity = null): array
    {
        $activity ??= $session->activity;
        $date = $session->occurs_on->toDateString();
        $hasSummary = $session->hasSummary();
        $status = $session->status;

        return [
            'key' => 'session-'.$session->ulid,
            'session_ulid' => $session->ulid,
            'activity_ulid' => $activity?->ulid,
            'title' => $session->activity_title,
            'kind' => $session->activity_kind,
            'kind_label' => $this->kindLabel($session->activity_kind),
            'occurs_on' => $date,
            'starts_at' => $this->instant($date, $session->starts_at),
            'ends_at' => $this->instant($date, $session->ends_at),
            'start_time' => substr($session->starts_at, 0, 5),
            'end_time' => substr($session->ends_at, 0, 5),
            'school_class' => $session->schoolClass === null ? null : [
                'ulid' => $session->schoolClass->ulid,
                'label' => $session->schoolClass->label,
            ],
            'status' => $status->value,
            'presentation' => $status->presentation($hasSummary),
            'presentation_label' => $status->presentationLabel($hasSummary),
            'summary' => $session->summary,
            'has_summary' => $hasSummary,
            'session_number' => $session->session_number,
            'numbering_enabled' => (bool) $activity?->numbering_enabled,
            'registrable' => true,
            'follows_schedule' => $activity !== null && $this->follows($activity, $session),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function virtual(TimetableActivity $activity, string $date, bool $registrable): array
    {
        $status = TimetableActivitySessionStatus::Planned;

        return [
            'key' => 'activity-'.$activity->ulid.'-'.$date,
            'session_ulid' => null,
            'activity_ulid' => $activity->ulid,
            'title' => $activity->title,
            'kind' => $activity->kind->value,
            'kind_label' => $activity->kind->label(),
            'occurs_on' => $date,
            'starts_at' => $this->instant($date, $activity->starts_at),
            'ends_at' => $this->instant($date, $activity->ends_at),
            'start_time' => substr($activity->starts_at, 0, 5),
            'end_time' => substr($activity->ends_at, 0, 5),
            'school_class' => $activity->schoolClass === null ? null : [
                'ulid' => $activity->schoolClass->ulid,
                'label' => $activity->schoolClass->label,
            ],
            'status' => $status->value,
            'presentation' => $status->presentation(false),
            'presentation_label' => $status->presentationLabel(false),
            'summary' => null,
            'has_summary' => false,
            'session_number' => null,
            'numbering_enabled' => (bool) $activity->numbering_enabled,
            'registrable' => $registrable,
            'follows_schedule' => true,
        ];
    }

    /**
     * A sessão ainda corresponde ao padrão do bloco atual?
     */
    private function follows(TimetableActivity $activity, TimetableActivitySession $session): bool
    {
        return $activity->occursOn($session->occurs_on->toDateString())
            && substr($session->starts_at, 0, 5) === substr($activity->starts_at, 0, 5)
            && substr($session->ends_at, 0, 5) === substr($activity->ends_at, 0, 5);
    }

    private function kindLabel(string $kind): string
    {
        return TimetableActivityKind::tryFrom($kind)?->label() ?? $kind;
    }

    /**
     * ISO 8601 com o desvio de Lisboa: é o que `lessons[].starts_at` já traz, e
     * o que a ordenação do ecrã compara.
     */
    private function instant(string $date, string $time): string
    {
        return CarbonImmutable::parse($date.' '.$time, self::TIMEZONE)->toIso8601String();
    }
}
