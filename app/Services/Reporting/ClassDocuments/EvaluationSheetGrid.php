<?php

namespace App\Services\Reporting\ClassDocuments;

use App\Models\ClassificationStatus;
use App\Services\Assessment\Export\EvaluationSheetCellText;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use App\Services\Assessment\SelfAssessmentReading;
use Illuminate\Support\Carbon;

/**
 * The printable grid of a CLASS — the Pauta de Avaliação — as a neutral
 * structure.
 *
 * THE PAUTA IS THE GRID. There is no second grid and no second calculation: this
 * composes an `EvaluationSheetDocument`, the very object the CSV and the Excel of
 * the Pauta are written from, and which is built either from the sheet as it
 * stands today (`fromLiveSheet`) or from a kept moment (`fromSnapshot`). The
 * source is told apart by the document and by nothing else, so a kept moment is
 * read from its frozen payload alone: this class has no way of reaching the
 * database, the clock or the present, and the tests hold it to that (§16).
 *
 * A PURE FUNCTION OF THE DOCUMENT AND THE OPTIONS. No queries, no `now()`, no
 * authorisation, no letterhead, no issue date: the caller authorises, loads the
 * document and adds what belongs to the act of printing. Two calls with the same
 * document give the same structure, and a document built with or without the
 * «proposal basis» (`BuildEvaluationSheet`'s `withProposalBasis`) gives the same
 * grid, because that basis is not read here — a stale-proposal warning is a
 * screen concern and would cost the other units' results to compute.
 *
 * THREE DIFFERENT STATEMENTS, NEVER ONE COLUMN (§3.3). What the engine
 * CALCULATED (the percentage, and the band it falls in), what the system
 * PROPOSED (the «Proposta» of the classification, and — per domain — the band
 * when nobody decided), and what the TEACHER DECIDED (the «Nível atribuído», and
 * the domain appreciation marked «prof.»), with the state of the classification
 * in words. A paper has no italics to tell them apart, so the distinction is
 * made by columns and by words, as in the CSV. A decision never overwrites the
 * proposal it replaced: the domain cell keeps it underneath. Nothing is
 * defaulted: «—» is a missing value, never zero.
 *
 * THE TEXT OF EACH CELL IS THE EXPORTS' TEXT (`EvaluationSheetCellText`): the
 * level, the proposal and the decision are read by the same code as the CSV and
 * the Excel, so the three cannot drift apart. The percentage is the same
 * one-decimal figure, with a comma.
 *
 * WHAT NEVER TRAVELS. The free text of an override (`override_reason`), every
 * identifier (enrolments, domains, levels), the coverage explanations, the
 * stale-proposal fields and the current-basis fields: this sheet may be handed to
 * someone who is not the teacher, and free text can hold anything. The
 * students' own words about themselves travel only when asked for.
 *
 * THE COLUMNS ARE CUT INTO BLOCKS HERE (`InstrumentGridLayout`), because dompdf
 * does not paginate horizontally. The unit that is never split is a DOMAIN (its
 * percentage, its appreciation and, when asked, the self-assessment): each block
 * is self-contained — its group header, its columns, its rows with «Nº» and
 * «Nome» repeated — and nothing is lost.
 *
 * OLD KEPT MOMENTS STILL OPEN. A payload that predates domain decisions, the
 * self-assessment, the classification status or the colour of a domain simply has
 * no such keys, and their absence reads as «nobody said anything», never as an
 * error and never as a zero.
 */
final class EvaluationSheetGrid
{
    private const DASH = '—';

    /** The width, in mm, of a domain with its percentage and its appreciation. */
    public const DOMAIN_WIDTH = 30.0;

    /** What the self-assessment adds to each domain, and to the summary. */
    public const SELF_ASSESSMENT_WIDTH = 16.0;

    /** @var array<string, float> the summary columns' widths, in mm */
    private const SUMMARY_WIDTHS = [
        'result' => 15.0,
        'self_assessment' => self::SELF_ASSESSMENT_WIDTH,
        'proposal' => 16.0,
        'assigned' => 18.0,
        'status' => 18.0,
        'coverage' => 24.0,
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(EvaluationSheetDocument $document, EvaluationSheetGridOptions $options): array
    {
        $withSelfAssessment = $options->selfAssessment && $document->hasSelfAssessment();
        $domains = $document->domains;
        $perDomain = $withSelfAssessment ? 3 : 2;

        $domainColumns = [];
        foreach ($domains as $index => $domain) {
            $name = (string) ($domain['name'] ?? '');
            $group = ['key' => 'd'.$index, 'label' => $name];

            $domainColumns[] = ['kind' => 'domain_result', 'label' => __('Resultado'), 'group' => $group];
            $domainColumns[] = ['kind' => 'domain_appreciation', 'label' => __('Apreciação'), 'group' => $group];

            if ($withSelfAssessment) {
                $domainColumns[] = ['kind' => 'domain_self_assessment', 'label' => __('Autoavaliação'), 'group' => $group];
            }
        }

        $summaryColumns = $this->summaryColumns($withSelfAssessment);

        $rows = [];
        $decided = 0;
        $anyCoverage = false;
        $anyDecidedDomain = false;

        foreach ($document->students as $student) {
            $row = $this->row($document, $student, $withSelfAssessment);
            $rows[] = $row['row'];
            $decided += $row['decided'] ? 1 : 0;
            $anyCoverage = $anyCoverage || $row['coverage'];
            $anyDecidedDomain = $anyDecidedDomain || $row['decided_domain'];
        }

        $domainWidth = self::DOMAIN_WIDTH + ($withSelfAssessment ? self::SELF_ASSESSMENT_WIDTH : 0.0);

        $plan = InstrumentGridLayout::plan(
            array_fill(0, count($domains), null),
            array_map(fn (array $column): float => self::SUMMARY_WIDTHS[$column['kind']], $summaryColumns),
            $options->orientation,
            $domainWidth,
        );

        $blocks = $this->blocks($plan['blocks'], $domainColumns, $summaryColumns, $rows, $perDomain);

        $pending = count($rows) - $decided;
        $provisional = $pending > 0;

        return [
            'title' => $document->momentLabel,
            'kind_label' => __('Grelha factual de avaliação da turma'),
            'subtitle' => $document->classLabel.' · '.$document->subject.' · '.$document->periodLabel,
            'meta' => [
                'class' => $document->classLabel,
                'subject' => $document->subject,
                'academic_year' => $document->academicYear,
                'period_kind' => $document->periodKindLabel,
                'period' => $document->periodLabel,
                'moment' => $document->momentLabel,
                'scope' => $document->scopeLabel,
                'reference_date' => $this->date($document->effectiveAt),
            ],
            'source' => $this->source($document),
            // PROVISÓRIO is not decoration: while any student has no level
            // assigned by the teacher the sheet shows proposals where the final
            // classification will be, and it must not circulate as if it were it.
            'state' => [
                'is_provisional' => $provisional,
                'label' => $provisional ? __('PROVISÓRIO') : __('Classificações tomadas'),
            ],
            'options' => [...$options->toArray(), 'self_assessment' => $withSelfAssessment],
            'self_assessment_available' => $document->hasSelfAssessment(),
            'orientation' => $plan['orientation'],
            'blocks' => $blocks,
            'summary' => [
                'students' => count($rows),
                'domains' => count($domains),
                'blocks' => count($blocks),
                'decided' => $decided,
                'pending' => $pending,
            ],
            'notes' => $this->notes($document, $anyCoverage, $anyDecidedDomain, $pending),
            // The warnings recorded when a moment was kept, as they were recorded.
            'warnings' => $document->warnings,
            'notice' => $document->isHistorical
                ? __('Documento gerado a partir de um momento guardado da Pauta; não reflete alterações posteriores.')
                : __('Documento gerado a partir dos registos à data de emissão; a classificação final é a decisão do professor registada na Pauta.'),
            'is_empty' => $rows === [],
            'empty_message' => __('Não há alunos nesta turma.'),
        ];
    }

    /**
     * @return list<array{kind: string, label: string, group: array{key: string, label: string}|null}>
     */
    private function summaryColumns(bool $withSelfAssessment): array
    {
        $global = ['key' => 'global', 'label' => __('Global')];
        $classification = ['key' => 'classification', 'label' => __('Classificação')];

        $columns = [['kind' => 'result', 'label' => __('Resultado'), 'group' => $global]];

        if ($withSelfAssessment) {
            $columns[] = ['kind' => 'self_assessment', 'label' => __('Autoavaliação'), 'group' => $global];
        }

        return [
            ...$columns,
            ['kind' => 'proposal', 'label' => __('Proposta do Lapispro'), 'group' => $classification],
            ['kind' => 'assigned', 'label' => __('Nível atribuído'), 'group' => $classification],
            ['kind' => 'status', 'label' => __('Estado'), 'group' => $classification],
            ['kind' => 'coverage', 'label' => __('Avisos de cobertura'), 'group' => null],
        ];
    }

    /**
     * One student's row: the cells of every column, in print order (the domains,
     * then the summary), and what the document needs to know about the row.
     *
     * @param  array<string, mixed>  $student
     * @return array{row: array{number: string, name: string, cells: list<array<string, mixed>>}, decided: bool, coverage: bool, decided_domain: bool}
     */
    private function row(EvaluationSheetDocument $document, array $student, bool $withSelfAssessment): array
    {
        /** @var array<array-key, array<string, mixed>> $studentDomains */
        $studentDomains = $student['domains'] ?? [];
        $byDomainId = [];

        foreach ($studentDomains as $studentDomain) {
            $byDomainId[(int) $studentDomain['domain_id']] = $studentDomain;
        }

        $cells = [];
        $warnings = [];
        $anyDecidedDomain = false;

        foreach ($document->domains as $domain) {
            $studentDomain = $byDomainId[(int) $domain['domain_id']] ?? null;
            $partial = ($studentDomain['has_coverage_warning'] ?? false) === true;

            $cells[] = $this->percentCell($studentDomain['normalized_value'] ?? null, $partial);

            $proposed = EvaluationSheetCellText::level($studentDomain);
            $decidedLevel = EvaluationSheetCellText::decidedLevel($studentDomain);
            $anyDecidedDomain = $anyDecidedDomain || $decidedLevel !== '';

            $cells[] = $this->appreciationCell($proposed, $decidedLevel);

            if ($withSelfAssessment) {
                $cells[] = $this->plainCell(SelfAssessmentReading::exportText($studentDomain['self_assessment'] ?? null));
            }

            if ($partial) {
                $warnings[] = (string) $domain['name'];
            }
        }

        /** @var array<string, mixed> $overall */
        $overall = $student['overall'] ?? [];
        $overallPartial = ($overall['has_coverage_warning'] ?? false) === true;

        if ($overallPartial) {
            array_unshift($warnings, __('Global'));
        }

        /** @var array<string, mixed>|null $classification */
        $classification = $student['classification'] ?? null;
        [$assigned] = EvaluationSheetCellText::decided($classification);

        $cells[] = [
            ...$this->percentCell($overall['normalized_value'] ?? null, $overallPartial),
            'detail' => EvaluationSheetCellText::level($overall) ?: null,
        ];

        if ($withSelfAssessment) {
            $cells[] = $this->plainCell(SelfAssessmentReading::exportText($student['self_assessment'] ?? null));
        }

        $proposal = EvaluationSheetCellText::proposed($classification);
        $cells[] = $this->plainCell($proposal);
        $cells[] = [
            'text' => $assigned === '' ? self::DASH : $assigned,
            // Nothing assigned says why, in words: a dash alone could be a
            // missing record or a decision not yet taken.
            'detail' => $assigned === '' ? ($classification === null ? __('sem classificação') : __('não decidido')) : null,
            'origin' => $assigned === '' ? null : 'decision',
            'mark' => null,
        ];
        $cells[] = $this->plainCell(ClassificationStatus::tryFrom((string) ($classification['status'] ?? ''))?->label() ?? '');
        $cells[] = $this->plainCell($warnings === [] ? '' : implode('; ', $warnings), emptyAsDash: false);

        $number = $student['class_number'] ?? null;

        return [
            'row' => [
                'number' => $number === null ? '' : (string) $number,
                'name' => (string) ($student['name'] ?? ''),
                'cells' => $cells,
            ],
            'decided' => $assigned !== '',
            // A star was printed on this row, so the sheet has to explain it.
            'coverage' => $this->hasStar($cells),
            'decided_domain' => $anyDecidedDomain,
        ];
    }

    /**
     * «78,5%» — the engine's figure with one decimal, as the Pauta shows it — or
     * the dash when there is none. A star marks a value that rests on partial or
     * missing elements.
     *
     * @return array{text: string, detail: null, origin: null, mark: null}
     */
    private function percentCell(mixed $value, bool $partial): array
    {
        $percentage = EvaluationSheetCellText::percentage($value, ',');

        return [
            'text' => $percentage === '' ? self::DASH : $percentage.'%'.($partial ? ' *' : ''),
            'detail' => null,
            'origin' => null,
            'mark' => null,
        ];
    }

    /**
     * What counts for the domain — the teacher's decision when there is one,
     * otherwise the proposal — with its origin in words, and the proposal kept
     * underneath when the decision replaced a different one.
     *
     * @return array<string, mixed>
     */
    private function appreciationCell(string $proposed, string $decided): array
    {
        if ($decided !== '') {
            return [
                'text' => $decided,
                'detail' => $proposed !== '' && $proposed !== $decided ? __('Proposta: :level', ['level' => $proposed]) : null,
                'origin' => 'decision',
                'mark' => __('prof.'),
            ];
        }

        return [
            'text' => $proposed === '' ? self::DASH : $proposed,
            'detail' => null,
            'origin' => $proposed === '' ? null : 'proposal',
            'mark' => null,
        ];
    }

    /**
     * @return array{text: string, detail: null, origin: null, mark: null}
     */
    private function plainCell(string $text, bool $emptyAsDash = true): array
    {
        return [
            'text' => $text === '' && $emptyAsDash ? self::DASH : $text,
            'detail' => null,
            'origin' => null,
            'mark' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cells
     */
    private function hasStar(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (str_ends_with((string) $cell['text'], ' *')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cuts the columns into the planned blocks and gives each one what it needs
     * to be drawn alone.
     *
     * @param  list<array{items: list<int>, summary: list<int>}>  $plan
     * @param  list<array{kind: string, label: string, group: array{key: string, label: string}}>  $domainColumns
     * @param  list<array{kind: string, label: string, group: array{key: string, label: string}|null}>  $summaryColumns
     * @param  list<array{number: string, name: string, cells: list<array<string, mixed>>}>  $rows
     * @return list<array<string, mixed>>
     */
    private function blocks(array $plan, array $domainColumns, array $summaryColumns, array $rows, int $perDomain): array
    {
        $domainCellCount = count($domainColumns);
        $columns = [...$domainColumns, ...$summaryColumns];
        $blocks = [];

        foreach ($plan as $index => $block) {
            // Positions in the flat order «domains, then summary». A domain is
            // `$perDomain` consecutive columns, and is never split.
            $positions = [];

            foreach ($block['items'] as $domainIndex) {
                for ($offset = 0; $offset < $perDomain; $offset++) {
                    $positions[] = $domainIndex * $perDomain + $offset;
                }
            }

            foreach ($block['summary'] as $summaryIndex) {
                $positions[] = $domainCellCount + $summaryIndex;
            }

            $blocks[] = [
                // «Parte 2 de 3» — only when the columns did not fit one block.
                'caption' => count($plan) > 1
                    ? __('Parte :part de :total', ['part' => $index + 1, 'total' => count($plan)])
                    : null,
                'columns' => array_map(fn (int $position): array => [
                    'kind' => $columns[$position]['kind'],
                    'label' => $columns[$position]['label'],
                ], $positions),
                'group_spans' => $this->groupSpans(array_map(fn (int $position): ?array => $columns[$position]['group'], $positions)),
                'rows' => array_map(fn (array $row): array => [
                    'number' => $row['number'],
                    'name' => $row['name'],
                    'cells' => array_map(fn (int $position): array => $row['cells'][$position], $positions),
                ], $rows),
            ];
        }

        return $blocks;
    }

    /**
     * The group header line of a block: consecutive columns of the same group
     * share a label, and columns without a group share an empty one. A group cut
     * between two blocks repeats its label in each.
     *
     * @param  list<array{key: string, label: string}|null>  $groups
     * @return list<array{label: string|null, span: int}>
     */
    private function groupSpans(array $groups): array
    {
        $labels = [];
        $counts = [];
        $previous = false;

        foreach ($groups as $group) {
            $key = $group['key'] ?? null;

            if ($labels !== [] && $key === $previous) {
                $counts[count($counts) - 1]++;
            } else {
                $labels[] = $group['label'] ?? null;
                $counts[] = 1;
            }

            $previous = $key;
        }

        return array_map(
            fn (?string $label, int $span): array => ['label' => $label, 'span' => $span],
            $labels,
            $counts,
        );
    }

    /**
     * Where the figures come from, in words: the sheet as it stands on the day of
     * issue, or a moment kept — with when, by whom, and that it is frozen.
     *
     * @return array{is_historical: bool, label: string, kept_at: string|null, author: string|null}
     */
    private function source(EvaluationSheetDocument $document): array
    {
        if (! $document->isHistorical) {
            return [
                'is_historical' => false,
                'label' => __('Estado à data de emissão'),
                'kept_at' => null,
                'author' => null,
            ];
        }

        $keptAt = $document->keptAt === null
            ? null
            : Carbon::parse($document->keptAt)->timezone((string) config('app.timezone'))->format('d/m/Y, H:i');

        return [
            'is_historical' => true,
            'label' => $keptAt === null
                ? __('Momento guardado')
                : __('Momento guardado a :moment', ['moment' => $keptAt]),
            'kept_at' => $document->keptAt,
            'author' => $document->authorName,
        ];
    }

    /**
     * The sentences that say how to read the sheet: the three statements, the
     * marks used, and — for a kept moment — that nothing has changed since.
     *
     * @return list<string>
     */
    private function notes(EvaluationSheetDocument $document, bool $anyCoverage, bool $anyDecidedDomain, int $pending): array
    {
        $notes = [
            __('O resultado é a percentagem calculada pelo motor de avaliação a partir das pontuações registadas. A proposta é do Lapispro; o nível atribuído é a decisão do professor e o estado indica em que ponto está.'),
        ];

        if ($anyDecidedDomain) {
            $notes[] = __('prof. — apreciação decidida pelo professor; sem marca, é a proposta do Lapispro para o resultado do domínio.');
        }

        if ($anyCoverage) {
            $notes[] = __('* O valor assenta em elementos parciais ou em falta.');
        }

        if ($pending > 0) {
            $notes[] = trans_choice(':count aluno ainda sem nível atribuído pelo professor.|:count alunos ainda sem nível atribuído pelo professor.', $pending, ['count' => $pending]);
        }

        if ($document->isHistorical) {
            $notes[] = __('Momento guardado: os valores são os do dia em que foi guardado e não refletem alterações posteriores.');
        }

        return $notes;
    }

    private function date(?string $date): ?string
    {
        return $date === null || $date === '' ? null : Carbon::parse($date)->format('d/m/Y');
    }
}
