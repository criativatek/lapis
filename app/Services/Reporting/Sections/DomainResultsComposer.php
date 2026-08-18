<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Assessment\Bc;
use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Análise dos resultados por domínio».
 *
 * THE LINE THIS SECTION MUST NOT CROSS (§13). «O domínio da Escrita apresentou
 * o resultado médio mais baixo» is a description of the data. «Os alunos não
 * estudam» is a claim about people, and no arrangement of these numbers
 * supports it. So this names which domain averaged highest and which lowest,
 * gives the figures, and stops — the interpretation belongs to the sections a
 * teacher fills in, and to the plan that allows them.
 *
 * IT DOES NOT RANK WHAT IS NOT COMPARABLE. Two domains averaged over different
 * numbers of students are stated with their own counts rather than lined up as
 * if they measured the same population; and where fewer than two domains have a
 * figure at all, there is no «mais elevado» to name and the section says only
 * what it knows.
 */
class DomainResultsComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::DomainResults;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $facts = $context->fact('domains');
        $domains = is_array($facts) ? array_values(array_filter($facts, 'is_array')) : [];

        if ($domains === []) {
            return ComposedSection::of(
                Absence::noData('os resultados por domínio'),
                [ContentSource::Statistics],
            );
        }

        $rows = $this->rows($domains, $context);
        $withValue = array_values(array_filter($rows, fn (array $row) => $row['value'] !== null));

        if ($withValue === []) {
            return ComposedSection::of(
                Absence::noData('os resultados por domínio'),
                [ContentSource::Statistics],
                ['domains' => $rows],
            );
        }

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $this->openingSentence($withValue),
                    $this->extremesSentence($withValue),
                ]),
                Phrase::paragraph([
                    $this->successSentence($withValue),
                    $this->coverageSentence($rows),
                ]),
            ]),
            [ContentSource::Statistics, ContentSource::Results],
            ['domains' => $rows],
        );
    }

    /**
     * One row per domain, with the figure the report actually quotes.
     *
     * THE ACCUMULATED FIGURE WHERE THE CLASS READING IS ACCUMULATED. A section
     * that quoted the period average under a headline stating the accumulated
     * one would put two different questions on the same page under one name
     * (§7).
     *
     * @param  list<array<string, mixed>>  $domains
     * @return list<array<string, mixed>>
     */
    protected function rows(array $domains, ReportContext $context): array
    {
        $accumulated = $context->fact('primary.kind') === 'accumulated';

        return array_map(function (array $domain) use ($accumulated): array {
            $value = $accumulated
                ? ($domain['accumulated_average'] ?? $domain['period_average'] ?? null)
                : ($domain['period_average'] ?? null);

            return [
                'label' => (string) ($domain['label'] ?? '—'),
                'value' => $value,
                'period_average' => $domain['period_average'] ?? null,
                'accumulated_average' => $domain['accumulated_average'] ?? null,
                'students_with_result' => (int) ($domain['students_with_result'] ?? 0),
                'students_without_result' => (int) ($domain['students_without_result'] ?? 0),
                'succeeded' => (int) ($domain['succeeded'] ?? 0),
                'placed' => (int) ($domain['placed'] ?? 0),
                'success_rate' => $domain['success_rate'] ?? null,
                'qualitative_band' => $domain['qualitative_band'] ?? null,
            ];
        }, $domains);
    }

    /**
     * A domain's figure as a comparable decimal string.
     *
     * The read model hands these over as DECIMAL strings and they stay strings:
     * comparing them as floats would introduce the very drift the assessment
     * core avoids, and this ordering decides which domain the report names as
     * highest. Bc::of is the project's own normaliser and rejects anything that
     * is not a number.
     *
     * @return numeric-string
     */
    protected function comparable(mixed $value): string
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';

        return is_numeric($text) ? Bc::of($text) : '0';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function openingSentence(array $rows): string
    {
        return Phrase::sentence(
            'Foram apurados resultados em',
            Phrase::count(count($rows), 'domínio', 'domínios'),
            ':',
            Phrase::items(array_map(
                fn (array $row) => $row['label'].' ('.Phrase::percentage($row['value']).')',
                $rows,
            )),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function extremesSentence(array $rows): ?string
    {
        if (count($rows) < 2) {
            return null;
        }

        $sorted = $rows;
        usort($sorted, fn (array $a, array $b) => bccomp(
            $this->comparable($b['value']),
            $this->comparable($a['value']),
            4,
        ));

        $highest = $sorted[0];
        $lowest = $sorted[count($sorted) - 1];

        // All equal: naming a highest and a lowest would manufacture a
        // difference the data does not have.
        if (bccomp($this->comparable($highest['value']), $this->comparable($lowest['value']), 4) === 0) {
            return 'Os domínios apresentaram resultados médios equivalentes entre si.';
        }

        return Phrase::sentence(
            'O domínio com resultado médio mais elevado foi',
            $highest['label'],
            '('.Phrase::percentage($highest['value']).')',
            ', e o mais baixo',
            $lowest['label'],
            '('.Phrase::percentage($lowest['value']).')',
        );
    }

    /**
     * Success within each domain — «5 de 6» — so a teacher can see which domain
     * is carrying the class and which is holding it back, without the report
     * concluding anything about why.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function successSentence(array $rows): ?string
    {
        $withPlaced = array_values(array_filter($rows, fn (array $row) => $row['placed'] > 0));

        if ($withPlaced === []) {
            return null;
        }

        $parts = array_map(
            fn (array $row) => $row['label'].' — '.$row['succeeded'].' de '.$row['placed'],
            $withPlaced,
        );

        return Phrase::sentence(
            'Quanto às menções positivas por domínio:',
            Phrase::items($parts),
        );
    }

    /**
     * Domains where part of the class has no result at all.
     *
     * Said as a count of students without evidence, never as a low average: a
     * domain nobody has been assessed in is not a weak domain (§41).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function coverageSentence(array $rows): ?string
    {
        $gaps = array_values(array_filter($rows, fn (array $row) => $row['students_without_result'] > 0));

        if ($gaps === []) {
            return null;
        }

        $parts = array_map(
            fn (array $row) => $row['label'].' ('.Phrase::students($row['students_without_result']).')',
            $gaps,
        );

        return Phrase::sentence(
            'Nos seguintes domínios existem alunos sem resultado apurado, que não entram nas respetivas médias:',
            Phrase::items($parts),
        );
    }
}
