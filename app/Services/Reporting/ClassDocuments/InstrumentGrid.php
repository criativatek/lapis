<?php

namespace App\Services\Reporting\ClassDocuments;

use App\Domain\Assessment\Bc;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\InstrumentStatus;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Services\Assessment\Analysis\BuildResultsAnalysis;
use App\Services\Assessment\Analysis\InstrumentResultsContext;
use App\Services\Assessment\ClassCohort;
use App\Services\Documents\DocumentIdentity;

/**
 * The printable grid of ONE instrument, as a neutral structure.
 *
 * A DOCUMENT MADE ON THE SPOT, NOT A REPORT. Nothing is written or snapshotted:
 * it reads the instrument as it stands, and the same structure is meant to feed
 * the on-screen preview and the PDF, so the two can only differ in how they draw
 * it. Every text — labels, formatted numbers, abbreviations, notices — is
 * composed here; a view only places it.
 *
 * NO SECOND CALCULATION OF THE RESULT. The result, the level and the domain
 * figures are `BuildResultsAnalysis::officialCellsFor()` — the very cells the
 * correction grid shows and Resultados lists — formatted exactly as the screen
 * formats them (one decimal, or the truncated two when rounding would have moved
 * the level). The proposal and the final classification are not here: neither
 * exists per instrument, and a document must not invent a number no screen shows.
 *
 * THE RAW SCORE IS THE ONE FIGURE THAT IS NEW. Until now it only existed in the
 * browser, in floating point. Here it is the sum of the points of the cells that
 * are `assessed`, in decimal arithmetic (`Bc`), over the instrument's total — and
 * nothing else: no percentage of it (a second percentage that disagrees with the
 * engine's), no weighting, no rule of absence. A student with nothing assessed
 * has no score, «—», never zero. It is NOT the result: with two or more domains,
 * or with questions that belong to none, the engine's figure differs from
 * points ÷ total on purpose, and the document says so.
 *
 * WHO APPEARS. Every student the instrument applies to, in the class order. A
 * student it does not apply to — joined after it, left before it, was not
 * attending the subject that day: the engine's own `out_of_scope` — is left out
 * and COUNTED, so the omission is visible and a name that has no business on the
 * sheet is not on it.
 *
 * WHAT NEVER TRAVELS. No photo, no identifier of any kind (the rows are told
 * apart by position), no birth date, school number or enrolment date, no
 * `state_reason`, no internal notes, no analysis notes: this sheet may be handed
 * to someone who is not the teacher, and free text can hold anything. Names are
 * only read for the students that appear.
 *
 * THE COLUMNS ARE CUT INTO BLOCKS HERE (`InstrumentGridLayout`), because dompdf
 * does not paginate horizontally: each block is self-contained — its columns,
 * its group header, its rows with «Nº» and «Nome» repeated — and nothing is lost.
 *
 * NO AI, NO NETWORK. Queries and arithmetic. The caller authorises: this builder
 * assumes `Gate::authorize('view', $instrument->schoolClass)` already happened.
 */
class InstrumentGrid
{
    /**
     * The states with a compact label of their own, in legend order. «Assessed»
     * is a number and «Pending» is the dash.
     */
    private const LABELLED_STATES = [
        ResultState::Absent,
        ResultState::AbsentJustified,
        ResultState::Exempt,
        ResultState::NotApplicable,
        ResultState::Annulled,
        ResultState::UnderReview,
    ];

    private const DASH = '—';

    public function __construct(
        protected DocumentIdentity $identity,
        protected BuildResultsAnalysis $analysis,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws InstrumentGridUnavailable when the instrument is a draft or annulled
     */
    public function build(Instrument $instrument, InstrumentGridOptions $options): array
    {
        if (in_array($instrument->status, [InstrumentStatus::Draft, InstrumentStatus::Cancelled], true)) {
            throw new InstrumentGridUnavailable(
                $instrument->status === InstrumentStatus::Draft
                    ? 'Um elemento de avaliação em preparação ainda não tem grelha para imprimir.'
                    : 'Um elemento de avaliação anulado não tem grelha para imprimir.',
            );
        }

        $instrument->loadMissing([
            'items.domainAllocations.domain', 'groups', 'type',
            'academicPeriod', 'schoolClass.subject', 'schoolClass.academicYear', 'schoolClass.organization.identity',
        ]);

        $class = $instrument->schoolClass;
        $items = $instrument->items->values();

        // ONE context for the cells AND for the notes, the absence rule and the
        // touched domains: two contexts would calculate the cohort twice.
        $context = new InstrumentResultsContext($instrument);
        $official = $this->analysis->officialCellsFor($context);
        $touched = $context->touchedDomains();
        $hasBands = $context->bands() !== [];

        $enrollments = ClassCohort::enrollmentsFor($class);
        $scores = $this->scoresByCell($instrument, array_values($enrollments->keys()->all()));

        $roster = [];
        $excluded = 0;

        foreach ($enrollments as $enrollmentId => $enrollment) {
            if (($official['students'][$enrollmentId]['status'] ?? null) === 'out_of_scope') {
                $excluded++;

                continue;
            }

            $roster[] = $enrollment;
        }

        // The columns, in the order they are printed: the questions, then the summary.
        $itemColumns = array_values($items->map(fn (InstrumentItem $item): array => [
            'kind' => 'item',
            'code' => $item->code,
            'group' => $instrument->groups->firstWhere('id', $item->instrument_group_id)?->label,
            'quota' => $this->number($item->points_possible).($item->is_bonus ? ' · '.__('bónus') : ''),
            'bonus' => (bool) $item->is_bonus,
        ])->all());

        $summaryColumns = $this->summaryColumns($instrument, $options, $touched, $hasBands);

        $rows = [];
        $statesSeen = [];
        $anyPartial = false;

        foreach ($roster as $enrollment) {
            $enrollmentId = (int) $enrollment->getKey();
            $row = $this->row($enrollment, array_values($items->all()), $scores[$enrollmentId] ?? [], $summaryColumns, $official['students'][$enrollmentId] ?? null, $instrument, $statesSeen);
            $anyPartial = $anyPartial || $row['partial'];
            $rows[] = $row;
        }

        $groupIds = array_values($items->map(fn (InstrumentItem $item): int => $item->instrument_group_id)->all());
        $plan = InstrumentGridLayout::plan(
            $groupIds,
            array_map(fn (array $column): float => $this->widthOf($column['kind']), $summaryColumns),
            $options->orientation,
        );

        $namedGroups = $instrument->groups->contains(fn ($group): bool => $group->label !== null && $group->label !== '');
        $blocks = $this->blocks($plan['blocks'], $itemColumns, $summaryColumns, $rows, $groupIds, $namedGroups);

        $identification = $context->identification();
        $issuedAt = now();
        $concluded = $instrument->status->isConcluded();

        return [
            'title' => $instrument->title,
            'kind_label' => __('Grelha do elemento de avaliação'),
            'subtitle' => $class->label.' · '.$class->subject->name.' · '.$instrument->academicPeriod->label
                .' · '.$instrument->applied_on->format('d/m/Y'),
            'identity' => $this->identityPayload($class),
            'issued_at' => $issuedAt->toIso8601String(),
            'issued_label' => __('Emitido a :moment', [
                'moment' => $issuedAt->copy()->timezone((string) config('app.timezone'))->format('d/m/Y, H:i'),
            ]),
            'meta' => [
                'class' => $class->label,
                'subject' => $class->subject->name,
                'period' => $instrument->academicPeriod->label,
                'applied_on' => $instrument->applied_on->format('d/m/Y'),
                'type' => $identification['type'],
                'purpose' => $identification['purpose_label'],
                'status' => $identification['status_label'],
            ],
            'is_diagnostic' => $context->isDiagnostic(),
            // PROVISÓRIO is not decoration: a figure of a correction still under
            // way must not circulate as if it were final.
            'state' => [
                'is_provisional' => ! $concluded,
                'label' => $concluded ? __('Correção concluída') : __('PROVISÓRIO'),
            ],
            'options' => $options->toArray(),
            'orientation' => $plan['orientation'],
            'blocks' => $blocks,
            'summary' => [
                'students' => count($rows),
                'excluded' => $excluded,
                'questions' => count($itemColumns),
                'blocks' => count($blocks),
                'domains' => count($touched),
            ],
            'excluded_note' => $this->excludedNote($excluded),
            'legend' => [
                'questions' => $options->legend ? $this->questionsLegend($instrument, array_values($items->all())) : null,
                'states' => $this->statesLegend($statesSeen),
            ],
            'notes' => $this->notes($context, $options, $touched, $anyPartial),
            'notice' => __('Documento gerado a partir dos registos à data de emissão; não é um relatório finalizado.'),
            'is_empty' => $rows === [],
            'empty_message' => __('Não há alunos abrangidos por este elemento de avaliação.'),
        ];
    }

    /**
     * The scores of the class's own enrolments, by enrolment and then by item.
     * A score of an enrolment that is not in the class — injected straight into
     * the database, say — is never read.
     *
     * @param  list<int>  $enrollmentIds
     * @return array<int, array<int, StudentItemScore>>
     */
    protected function scoresByCell(Instrument $instrument, array $enrollmentIds): array
    {
        $byCell = [];

        $scores = StudentItemScore::query()
            ->where('instrument_id', $instrument->getKey())
            ->whereIn('enrollment_id', $enrollmentIds)
            ->get();

        foreach ($scores as $score) {
            $byCell[(int) $score->enrollment_id][(int) $score->instrument_item_id] = $score;
        }

        return $byCell;
    }

    /**
     * The summary columns this document has, in print order.
     *
     * @param  list<array{id: int, name: string, weight_percent: string|null}>  $touched
     * @return list<array{kind: string, label: string, quota: string|null, domain_key: string|null}>
     */
    protected function summaryColumns(Instrument $instrument, InstrumentGridOptions $options, array $touched, bool $hasBands): array
    {
        $columns = [];

        if ($options->rawScore) {
            $columns[] = [
                'kind' => 'raw',
                'label' => __('Pontuação bruta'),
                'quota' => $instrument->total_points === null ? null : $this->number($instrument->total_points),
                'domain_key' => null,
            ];
        }

        if ($options->result) {
            $columns[] = ['kind' => 'result', 'label' => __('Resultado'), 'quota' => null, 'domain_key' => null];

            if ($hasBands) {
                $columns[] = ['kind' => 'level', 'label' => __('Nível'), 'quota' => null, 'domain_key' => null];
            }
        }

        // With one domain the domain's figure IS the global: printing both would
        // be one number twice.
        if ($options->domains && count($touched) >= 2) {
            foreach ($touched as $domain) {
                $columns[] = ['kind' => 'domain', 'label' => $domain['name'], 'quota' => null, 'domain_key' => 'd'.$domain['id']];
            }
        }

        return $columns;
    }

    /**
     * One student's row: every cell, in print order (the questions, then the summary).
     *
     * @param  list<InstrumentItem>  $items
     * @param  array<int, StudentItemScore>  $scores  this student's, by item id
     * @param  list<array{kind: string, label: string, quota: string|null, domain_key: string|null}>  $summaryColumns
     * @param  array<string, mixed>|null  $officialCells
     * @param  array<string, bool>  $statesSeen  collects the states that appear, for the legend
     * @return array{number: string, name: string, cells: list<array<string, mixed>>, partial: bool}
     */
    protected function row(
        Enrollment $enrollment,
        array $items,
        array $scores,
        array $summaryColumns,
        ?array $officialCells,
        Instrument $instrument,
        array &$statesSeen,
    ): array {
        $cells = [];
        $sum = null;
        $marked = 0;

        foreach ($items as $item) {
            $score = $scores[(int) $item->getKey()] ?? null;
            $state = $score !== null ? $score->result_state : ResultState::Pending;

            if ($state !== ResultState::Pending) {
                $marked++;
            }

            $statesSeen[$state->value] = true;

            $text = match (true) {
                $state === ResultState::Assessed && $score !== null && $score->points_earned !== null => $this->number($score->points_earned),
                $state === ResultState::Assessed => self::DASH,
                $state === ResultState::Pending => self::DASH,
                default => $this->abbreviation($state),
            };

            if ($state === ResultState::Assessed && $score !== null && $score->points_earned !== null) {
                $sum = Bc::add($sum ?? '0', Bc::of((string) $score->points_earned));
            }

            $cells[] = ['text' => $text, 'detail' => null, 'state' => $state->value, 'negative' => null];
        }

        // The screen's own rule: some cells dealt with and others still waiting.
        $partial = $marked > 0 && $marked < count($items);

        foreach ($summaryColumns as $column) {
            $cells[] = match ($column['kind']) {
                'raw' => $this->rawCell($sum, $instrument, $partial),
                'result' => $this->resultCell($officialCells),
                'level' => $this->levelCell($officialCells),
                default => $this->domainCell($officialCells, (string) $column['domain_key']),
            };
        }

        return [
            'number' => $enrollment->class_number === null ? self::DASH : (string) $enrollment->class_number,
            'name' => optional($enrollment->student->identity)->display_name ?? __('(sem identidade)'),
            'cells' => $cells,
            'partial' => $partial,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rawCell(?string $sum, Instrument $instrument, bool $partial): array
    {
        if ($sum === null) {
            return ['text' => self::DASH, 'detail' => null, 'state' => null, 'negative' => null];
        }

        $text = $this->number($sum);

        if ($instrument->total_points !== null) {
            $text .= '/'.$this->number($instrument->total_points);
        }

        return ['text' => $text.($partial ? ' *' : ''), 'detail' => null, 'state' => null, 'negative' => null];
    }

    /**
     * The engine's global figure — or, when there is none, the reason, as the
     * screen shows it («Por classificar», «Ausente»…).
     *
     * @param  array<string, mixed>|null  $official
     * @return array<string, mixed>
     */
    protected function resultCell(?array $official): array
    {
        $global = $official['global'] ?? null;
        $percent = is_array($global) ? $this->percent($global) : null;

        if ($percent === null) {
            return [
                'text' => (string) ($official['status_label'] ?? self::DASH),
                'detail' => null,
                'state' => $official['status'] ?? null,
                'negative' => null,
            ];
        }

        return [
            'text' => $percent.(($global['is_partial'] ?? false) ? ' *' : ''),
            'detail' => null,
            'state' => 'classified',
            'negative' => $global['below_threshold'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $official
     * @return array<string, mixed>
     */
    protected function levelCell(?array $official): array
    {
        $global = $official['global'] ?? null;
        $label = is_array($global) ? ($global['band']['label'] ?? null) : null;

        return [
            'text' => $label === null ? self::DASH : (string) $label,
            'detail' => null,
            'state' => null,
            'negative' => is_array($global) && $label !== null ? ($global['below_threshold'] ?? null) : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $official
     * @return array<string, mixed>
     */
    protected function domainCell(?array $official, string $domainKey): array
    {
        $cell = $official['domains'][$domainKey] ?? null;
        $percent = is_array($cell) ? $this->percent($cell) : null;

        if ($percent === null) {
            return ['text' => self::DASH, 'detail' => null, 'state' => null, 'negative' => null];
        }

        return [
            'text' => $percent.(($cell['is_partial'] ?? false) ? ' *' : ''),
            'detail' => $cell['band']['label'] ?? null,
            'state' => 'classified',
            'negative' => $cell['below_threshold'] ?? null,
        ];
    }

    /**
     * «72,5 %» — or the truncated «49,49 %» when the engine says one decimal
     * would have moved the level. The same choice the screen makes.
     *
     * @param  array<string, mixed>  $cell
     */
    protected function percent(array $cell): ?string
    {
        $precise = $cell['value_precise'] ?? null;
        $raw = $precise ?? ($cell['value'] ?? null);

        if ($raw === null) {
            return null;
        }

        return str_replace('.', ',', Bc::truncate(Bc::of((string) $raw), $precise !== null ? 2 : 1)).' %';
    }

    /**
     * Cuts the columns into the planned blocks and gives each one what it needs
     * to be drawn alone.
     *
     * @param  list<array{items: list<int>, summary: list<int>}>  $plan
     * @param  list<array<string, mixed>>  $itemColumns
     * @param  list<array{kind: string, label: string, quota: string|null, domain_key: string|null}>  $summaryColumns
     * @param  list<array{number: string, name: string, cells: list<array<string, mixed>>, partial: bool}>  $rows
     * @param  list<int|null>  $groupIds
     * @return list<array<string, mixed>>
     */
    protected function blocks(array $plan, array $itemColumns, array $summaryColumns, array $rows, array $groupIds, bool $namedGroups): array
    {
        $itemCount = count($itemColumns);
        $blocks = [];

        foreach ($plan as $block) {
            // Positions in the flat order «questions, then summary».
            $positions = array_merge($block['items'], array_map(fn (int $index): int => $itemCount + $index, $block['summary']));

            $columns = array_map(
                fn (int $position): array => $position < $itemCount
                    ? $itemColumns[$position]
                    : $this->summaryColumnPayload($summaryColumns[$position - $itemCount]),
                $positions,
            );

            $blocks[] = [
                'columns' => $columns,
                'group_spans' => $namedGroups ? $this->groupSpans($positions, $itemColumns, $groupIds) : null,
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
     * @param  array{kind: string, label: string, quota: string|null, domain_key: string|null}  $column
     * @return array<string, mixed>
     */
    protected function summaryColumnPayload(array $column): array
    {
        return ['kind' => $column['kind'], 'label' => $column['label'], 'quota' => $column['quota']];
    }

    /**
     * The group header line of a block: consecutive questions of the same group
     * share a label, the summary columns share an empty one. A group that is cut
     * between two blocks repeats its label in each.
     *
     * @param  list<int>  $positions
     * @param  list<array<string, mixed>>  $itemColumns
     * @param  list<int|null>  $groupIds
     * @return list<array{label: string|null, span: int}>
     */
    protected function groupSpans(array $positions, array $itemColumns, array $groupIds): array
    {
        $labels = [];
        $counts = [];
        $previous = null;

        foreach ($positions as $position) {
            $isItem = $position < count($itemColumns);
            $key = $isItem ? 'g'.($groupIds[$position] ?? 'none') : 'summary';

            if ($counts !== [] && $previous === $key) {
                $counts[count($counts) - 1]++;

                continue;
            }

            $previous = $key;
            $label = $isItem ? $itemColumns[$position]['group'] : null;
            $labels[] = $label === '' ? null : $label;
            $counts[] = 1;
        }

        return array_map(
            fn (?string $label, int $span): array => ['label' => $label, 'span' => $span],
            $labels,
            $counts,
        );
    }

    /**
     * The table of questions: code, group, description, quota and the domains it
     * counts towards. Descriptions are the teacher's own words about the
     * questions, not about any student.
     *
     * @param  list<InstrumentItem>  $items
     * @return list<array<string, mixed>>
     */
    protected function questionsLegend(Instrument $instrument, array $items): array
    {
        return array_map(fn (InstrumentItem $item): array => [
            'code' => $item->code,
            'group' => $instrument->groups->firstWhere('id', $item->instrument_group_id)?->label,
            'label' => $item->label,
            'quota' => $this->number($item->points_possible),
            'bonus' => (bool) $item->is_bonus,
            'domains' => $item->domainAllocations->map(
                fn ($allocation): string => $allocation->domain->name.' '.$this->number($allocation->allocation_percent).' %',
            )->values()->all(),
        ], $items);
    }

    /**
     * What each compact label means — only the states that actually appear, and
     * «—» first, spelled out as not being a zero.
     *
     * @param  array<string, bool>  $statesSeen
     * @return list<array{short: string, label: string}>
     */
    protected function statesLegend(array $statesSeen): array
    {
        $legend = [];

        if (isset($statesSeen[ResultState::Pending->value])) {
            $legend[] = ['short' => self::DASH, 'label' => __('Por avaliar (não é zero)')];
        }

        foreach (self::LABELLED_STATES as $state) {
            if (isset($statesSeen[$state->value])) {
                $legend[] = ['short' => $this->abbreviation($state), 'label' => $state->label()];
            }
        }

        return $legend;
    }

    /**
     * @param  list<array{id: int, name: string, weight_percent: string|null}>  $touched
     * @return list<string>
     */
    protected function notes(InstrumentResultsContext $context, InstrumentGridOptions $options, array $touched, bool $anyPartial): array
    {
        $notes = $context->notes();

        if ($options->result) {
            $notes[] = __('O resultado é calculado pelo motor de avaliação a partir das pontuações registadas; não é a classificação do período.');
        }

        if ($options->domains && count($touched) >= 2) {
            $weights = [];

            foreach ($touched as $domain) {
                if ($domain['weight_percent'] !== null) {
                    $weights[] = $domain['name'].' '.$this->number($domain['weight_percent']).' %';
                }
            }

            if ($weights !== []) {
                $notes[] = __('Peso dos domínios no perfil da turma: :weights.', ['weights' => implode(', ', $weights)]);
            }
        }

        if ($anyPartial) {
            $notes[] = __('* Avaliação parcial: há questões ainda por avaliar.');
        }

        return $notes;
    }

    protected function excludedNote(int $excluded): ?string
    {
        if ($excluded === 0) {
            return null;
        }

        if ($excluded === 1) {
            return __('1 aluno não abrangido por este elemento de avaliação não consta.');
        }

        return __(':count alunos não abrangidos por este elemento de avaliação não constam.', ['count' => $excluded]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function identityPayload(SchoolClass $class): array
    {
        $identity = $this->identity->for($class->organization);

        return [
            'name' => $identity['name'],
            'header_lines' => $identity['header_lines'],
            'footer_note' => $identity['footer_note'],
            'is_configured' => $identity['is_configured'],
            'logo_url' => null,
            'logo' => null,
        ];
    }

    protected function widthOf(string $kind): float
    {
        return match ($kind) {
            'raw' => InstrumentGridLayout::RAW_WIDTH,
            'result' => InstrumentGridLayout::RESULT_WIDTH,
            'level' => InstrumentGridLayout::LEVEL_WIDTH,
            default => InstrumentGridLayout::DOMAIN_WIDTH,
        };
    }

    /**
     * The compact label of a state, as the correction grid writes it. A match with
     * no default: a state added later fails loudly here instead of printing
     * nothing.
     */
    protected function abbreviation(ResultState $state): string
    {
        return match ($state) {
            ResultState::Absent => 'Aus',
            ResultState::AbsentJustified => 'AusJ',
            ResultState::Exempt => 'Disp',
            ResultState::NotApplicable => 'N/A',
            ResultState::Annulled => 'Anul',
            ResultState::UnderReview => 'Rev',
            ResultState::Assessed, ResultState::Pending => self::DASH,
        };
    }

    /**
     * A decimal as the teacher reads it: no trailing zeros, comma as separator.
     * «9.0000» is «9», «7.5000» is «7,5», and a deduction keeps its minus sign.
     */
    protected function number(string|int|float $value): string
    {
        $text = (string) $value;

        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        if ($text === '' || $text === '-' || $text === '-0') {
            $text = '0';
        }

        return str_replace('.', ',', $text);
    }
}
