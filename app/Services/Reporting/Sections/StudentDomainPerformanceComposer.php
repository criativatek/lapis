<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Desempenho por domínio», for one student.
 *
 * A DOMAIN WITH NO EVIDENCE IS NOT A WEAK DOMAIN (§41). It is listed as having
 * no result, kept out of every comparison, and never given a figure of any
 * kind. That distinction is the whole reason this section counts what is
 * missing instead of quietly showing fewer rows.
 *
 * The names come from the class read — one source for the labels, so a domain
 * cannot be called one thing in the class report and another here.
 */
class StudentDomainPerformanceComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::StudentDomainPerformance;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $student = $context->fact('student');

        if (! is_array($student)) {
            return ComposedSection::empty();
        }

        $labels = $this->domainLabels($context);
        $accumulated = $context->fact('primary.kind') === 'accumulated';

        $rows = [];

        foreach ((array) ($student['domains'] ?? []) as $cell) {
            if (! is_array($cell)) {
                continue;
            }

            $id = (int) ($cell['domain_id'] ?? 0);

            $rows[] = [
                'label' => $labels[$id] ?? '—',
                'value' => $accumulated
                    ? ($cell['accumulated_average'] ?? $cell['weighted_average'] ?? null)
                    : ($cell['weighted_average'] ?? null),
                'mention' => is_array($cell['mention'] ?? null) ? ($cell['mention']['label'] ?? null) : null,
                'coverage_warning' => (bool) ($cell['coverage_warning'] ?? false),
            ];
        }

        if ($rows === []) {
            return ComposedSection::of(Absence::noData('os domínios'), [ContentSource::Results]);
        }

        $withValue = array_values(array_filter($rows, fn (array $row) => $row['value'] !== null));
        $without = array_values(array_filter($rows, fn (array $row) => $row['value'] === null));

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $this->valuesSentence($withValue),
                    $this->missingSentence($without),
                ]),
            ]),
            [ContentSource::Results],
            ['domains' => $rows],
        );
    }

    /**
     * @return array<int, string>
     */
    protected function domainLabels(ReportContext $context): array
    {
        $labels = [];

        foreach ((array) $context->fact('domains', []) as $domain) {
            if (is_array($domain) && isset($domain['domain_id'])) {
                $labels[(int) $domain['domain_id']] = (string) ($domain['label'] ?? '—');
            }
        }

        return $labels;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function valuesSentence(array $rows): ?string
    {
        if ($rows === []) {
            return null;
        }

        $parts = array_map(function (array $row): string {
            $text = $row['label'].' — '.Phrase::percentage($row['value']);

            // The mention rides along beside the figure, never in its place.
            return $row['mention'] === null ? $text : $text.' ('.$row['mention'].')';
        }, $rows);

        return Phrase::sentence('Por domínio:', Phrase::items($parts));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function missingSentence(array $rows): ?string
    {
        if ($rows === []) {
            return null;
        }

        return Phrase::sentence(
            count($rows) === 1
                ? 'Não existe resultado apurado no domínio'
                : 'Não existem resultados apurados nos domínios',
            Phrase::items(array_map(fn (array $row) => (string) $row['label'], $rows)),
            ', o que não corresponde a um resultado negativo',
        );
    }
}
