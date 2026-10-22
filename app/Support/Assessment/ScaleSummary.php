<?php

namespace App\Support\Assessment;

use App\Models\Scale;

/**
 * A scale as a document may carry it: its name, its kind and its levels in order — and
 * nothing else.
 *
 * ONE WRITER FOR EVERYTHING THAT FREEZES OR COMPARES A SCALE. The Pauta carries it so that
 * a kept moment can say which levels existed (and in what order) without asking today's
 * scale; the timeline of the advanced analysis carries it so two moments can be told
 * comparable or not by what the scale WAS. Two writers would be two opinions about what
 * «the same scale» means.
 *
 * No identifier of any kind: a level is told by its code, its label and its place in the
 * order. `is_negative` is the scale's own line — the school decides where it runs, and
 * every reading that speaks of a result «below the positive level» takes it from here.
 */
final class ScaleSummary
{
    /**
     * @return array{name: string, kind: string, levels: list<array{code: string, label: string, sequence: int, is_negative: bool}>}|null
     */
    public static function of(?Scale $scale): ?array
    {
        if ($scale === null) {
            return null;
        }

        return [
            'name' => (string) $scale->name,
            'kind' => (string) $scale->kind,
            'levels' => array_values($scale->levels->sortBy('sequence')->map(fn ($level): array => [
                'code' => (string) $level->code,
                'label' => (string) $level->label,
                'sequence' => (int) $level->sequence,
                'is_negative' => (bool) $level->is_negative,
            ])->all()),
        ];
    }
}
