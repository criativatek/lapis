<?php

namespace App\Services\Reporting;

use App\Domain\Reporting\SectionKey;
use App\Models\Report;
use App\Services\Reporting\Narrative\Phrase;

/**
 * «Desde o relatório anterior» (§35).
 *
 * WHAT MOVED, AND NOTHING ABOUT WHY. «Média acumulada: 61,4% → 65,2%» is two
 * figures from two moments. Whether the class improved, whether a measure
 * worked, whether anything at all caused it — none of that is here, and none of
 * it is inferable from a pair of numbers. The brief is explicit and so is this
 * service: differences are shown, causation is not offered.
 *
 * IT COMPARES DOCUMENTS, NOT DATABASES. The earlier figures come from the base
 * report itself: from its frozen `document` when it was finalized, and from its
 * current sections when it is still a draft. Re-querying the assessment core
 * «as it was in February» is exactly what §30 rules out — and would be wrong
 * anyway, because a report is a statement made at a moment, not a view onto one.
 *
 * A COMPARISON THAT CANNOT BE MADE IS OMITTED, never shown as a change from
 * nothing. Two reports over different periods, one of which had no
 * classifications, produce no row for the success rate rather than «— → 91,7%».
 */
class ReportComparison
{
    /**
     * @return array<string, mixed>|null
     */
    public function for(Report $report): ?array
    {
        $base = $report->basedOn;

        if ($base === null) {
            return null;
        }

        $before = $this->sectionData($base);
        $after = $this->sectionData($report);

        $rows = array_values(array_filter([
            $this->averageRow($before, $after),
            $this->successRow($before, $after),
            $this->negativeRow($before, $after),
            $this->interventionRow($before, $after),
            $this->rosterRow($before, $after),
        ]));

        if ($rows === []) {
            return null;
        }

        return [
            'base' => [
                'ulid' => $base->ulid,
                'title' => $base->title,
                'scope_label' => $base->scope_label,
                'status' => $base->status->value,
            ],
            'rows' => $rows,
            // Said in the payload, so a screen cannot forget to say it.
            'caveat' => 'As diferenças acima são factuais. O relatório não atribui causa a nenhuma delas.',
        ];
    }

    /**
     * The section payloads of a report, whichever life it is in.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function sectionData(Report $report): array
    {
        $data = [];

        if ($report->isFinalized() && is_array($report->document)) {
            foreach ((array) ($report->document['sections'] ?? []) as $section) {
                if (is_array($section) && is_array($section['data'] ?? null)) {
                    $data[(string) $section['key']] = $section['data'];
                }
            }

            return $data;
        }

        foreach ($report->sections()->get() as $section) {
            if (is_array($section->data)) {
                $data[$section->key] = $section->data;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     * @return array<string, mixed>|null
     */
    protected function averageRow(array $before, array $after): ?array
    {
        $from = data_get($before, SectionKey::OverallAssessment->value.'.summary.primary_average');
        $to = data_get($after, SectionKey::OverallAssessment->value.'.summary.primary_average');

        return $this->row('Resultado médio', Phrase::percentage($from), Phrase::percentage($to));
    }

    /**
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     * @return array<string, mixed>|null
     */
    protected function successRow(array $before, array $after): ?array
    {
        $from = data_get($before, SectionKey::OverallAssessment->value.'.summary.success.rate');
        $to = data_get($after, SectionKey::OverallAssessment->value.'.summary.success.rate');

        return $this->row('Taxa de sucesso', Phrase::percentage($from), Phrase::percentage($to));
    }

    /**
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     * @return array<string, mixed>|null
     */
    protected function negativeRow(array $before, array $after): ?array
    {
        $from = data_get($before, SectionKey::OverallAssessment->value.'.summary.success.failed');
        $to = data_get($after, SectionKey::OverallAssessment->value.'.summary.success.failed');

        return $this->row(
            'Classificações negativas atribuídas',
            $from === null ? null : (string) $from,
            $to === null ? null : (string) $to,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     * @return array<string, mixed>|null
     */
    protected function interventionRow(array $before, array $after): ?array
    {
        $from = data_get($before, SectionKey::InterventionsSummary->value.'.total');
        $to = data_get($after, SectionKey::InterventionsSummary->value.'.total');

        return $this->row(
            'Intervenções registadas',
            $from === null ? null : (string) $from,
            $to === null ? null : (string) $to,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     * @return array<string, mixed>|null
     */
    protected function rosterRow(array $before, array $after): ?array
    {
        $from = data_get($before, SectionKey::ClassIdentification->value.'.roster.active');
        $to = data_get($after, SectionKey::ClassIdentification->value.'.roster.active');

        return $this->row(
            'Alunos na turma',
            $from === null ? null : (string) $from,
            $to === null ? null : (string) $to,
        );
    }

    /**
     * A row, or nothing.
     *
     * Both ends have to exist: a change from a figure that was never recorded
     * is not a change, and «— → 3» invites a reading of «subiu de zero».
     * Identical ends are dropped too — a list of things that did not move is
     * noise the reader has to filter.
     *
     * @return array<string, mixed>|null
     */
    protected function row(string $label, ?string $from, ?string $to): ?array
    {
        if ($from === null || $to === null || $from === $to) {
            return null;
        }

        return ['label' => $label, 'from' => $from, 'to' => $to];
    }
}
