<?php

namespace App\Support\Usage;

use App\Models\CalendarEvent;
use App\Models\Instrument;
use App\Models\InstrumentGroup;
use App\Models\InstrumentItem;
use App\Models\Intervention;
use App\Models\InterventionSupportMeasure;
use App\Models\Report;
use App\Models\ReportSection;

/**
 * O estado de um registo e do que lhe pertence, lido com consultas NOVAS (nunca
 * carregando relações no modelo do route binding, para não mudar o que a
 * resposta devolve a seguir), para que uma edição só conte quando algo mudou.
 *
 * Devolvem arrays que o `UsageRecorder::snapshot()` transforma numa impressão
 * digital em memória. Nunca se guardam: contêm conteúdos (textos, notas), por
 * isso só servem para comparar antes e depois, dentro do pedido.
 *
 * O que entra é o CONTEÚDO, não os identificadores nem as marcas de tempo: um
 * construtor que recria linhas com ids novos não é uma alteração.
 */
final class UsageSnapshots
{
    /** @return array<string, mixed> */
    public static function report(int $reportId): array
    {
        $recorder = app(UsageRecorder::class);

        $sections = ReportSection::query()
            ->where('report_id', $reportId)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ReportSection $section): array => $recorder->stateOf($section, ['report_id']))
            ->all();

        return [
            'report' => $recorder->stateOf(Report::query()->find($reportId)),
            'sections' => $sections,
        ];
    }

    /** @return array<string, mixed> */
    public static function intervention(int $interventionId): array
    {
        $recorder = app(UsageRecorder::class);
        $intervention = Intervention::query()->find($interventionId);

        if ($intervention === null) {
            return [];
        }

        $participants = $intervention->participants()->pluck($intervention->participants()->getRelated()->getQualifiedKeyName())->all();
        sort($participants);

        return [
            'intervention' => $recorder->stateOf($intervention),
            'participants' => $participants,
            'measures' => $intervention->supportMeasures()->get()
                ->map(fn (InterventionSupportMeasure $measure): array => $recorder->stateOf($measure, ['intervention_id']))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function instrument(int $instrumentId): array
    {
        $recorder = app(UsageRecorder::class);

        $groups = InstrumentGroup::query()->where('instrument_id', $instrumentId)->orderBy('sequence')->orderBy('id')->get();
        $groupLabels = $groups->mapWithKeys(fn (InstrumentGroup $group): array => [$group->getKey() => $group->label]);

        return [
            'instrument' => $recorder->stateOf(Instrument::query()->find($instrumentId)),
            'groups' => $groups
                ->map(fn (InstrumentGroup $group): array => $recorder->stateOf($group, ['instrument_id']))
                ->all(),
            // O grupo vai pelo rótulo: os ids dos grupos podem ser recriados.
            'items' => InstrumentItem::query()
                ->where('instrument_id', $instrumentId)
                ->orderBy('sequence')
                ->orderBy('id')
                ->get()
                ->map(fn (InstrumentItem $item): array => [
                    ...$recorder->stateOf($item, ['instrument_id', 'instrument_group_id']),
                    'group' => $groupLabels->get($item->instrument_group_id),
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function calendarEvent(int $eventId): array
    {
        $event = CalendarEvent::query()->find($eventId);

        if ($event === null) {
            return [];
        }

        $classes = $event->schoolClasses()->pluck($event->schoolClasses()->getRelated()->getQualifiedKeyName())->all();
        sort($classes);

        return [
            'event' => app(UsageRecorder::class)->stateOf($event),
            'classes' => $classes,
        ];
    }
}
