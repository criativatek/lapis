<?php

namespace App\Services\Assessment\Analysis;

use App\Domain\Assessment\Analysis\AnalysisBand;
use App\Domain\Assessment\Analysis\DescriptiveReport;
use App\Domain\Assessment\Analysis\Observation;
use App\Domain\Assessment\Analysis\ObservationStatus;
use App\Domain\Assessment\Analysis\ResultsAnalyzer;
use App\Domain\Assessment\Bc;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\ResultsAnalysisNote;
use App\Services\Assessment\ClassCohort;

/**
 * Orchestrates a results context → the analyzer → the descriptive report →
 * the Inertia payload described in the design spec's Annex A. It never
 * computes a statistic itself — that is `ResultsAnalyzer`'s and
 * `DescriptiveReport`'s job — and it never writes anything: viewing never
 * touches a classification, a snapshot, or a score.
 */
class BuildResultsAnalysis
{
    public function __construct(protected ResultsAnalyzer $analyzer) {}

    /**
     * @return array<string, mixed>
     */
    public function forInstrument(Instrument $instrument, bool $canEdit, bool $includeIndividual = true): array
    {
        $context = new InstrumentResultsContext($instrument);
        $bands = $context->bands();
        $threshold = $context->threshold();

        $availability = $this->availabilityPayload($instrument);
        $contextPayload = $this->contextPayload($context, $bands, $threshold);

        if (! $availability['official']) {
            // Not concluded: no official statistics exist, and none are
            // computed — `dimensions()` (and the engine call inside it) is
            // never invoked for a provisional/absent instrument.
            $note = $this->notePayload($instrument);

            return [
                'availability' => $availability,
                'context' => $contextPayload,
                'students' => [],
                'dimensions' => [],
                'report' => null,
                'note' => $note,
                'can_edit' => $canEdit,
                'links' => $this->links($instrument, $includeIndividual),
            ];
        }

        $dimensions = $context->dimensions();

        $dimensionPayload = [];
        foreach ($dimensions as $dimension) {
            $dimensionPayload[] = [
                'key' => $dimension['key'],
                'label' => $dimension['label'],
                'analysis' => $this->analyzer->analyse($dimension['observations'], $bands, $threshold),
            ];
        }

        $students = $includeIndividual
            ? $this->studentsPayload($instrument, $dimensions, $bands, $threshold)
            : [];

        $report = DescriptiveReport::compose($contextPayload, $dimensionPayload);
        $report['generated_at'] = now()->toIso8601String();

        $note = $this->notePayload($instrument);

        return [
            'availability' => $availability,
            'context' => $contextPayload,
            'students' => $students,
            'dimensions' => $dimensionPayload,
            'report' => $report,
            'note' => $note,
            'can_edit' => $canEdit,
            'links' => $this->links($instrument, $includeIndividual),
        ];
    }

    /**
     * The grid's «Classificação oficial/provisória» column (design addendum
     * §4): the SAME `InstrumentResultsContext` + `cell()` code path Results
     * uses to build its own `students` payload — `studentsPayload()` itself —
     * so the grid and the Resultados tab can never show different numbers for
     * the same instrument. Computed for any non-draft instrument, including
     * `in_correction` (the grid needs the working values while correcting;
     * whether they are "official" is a separate question the caller answers
     * from `InstrumentStatus::isConcluded()`).
     *
     * @return array{domains: list<array{key: string, id: int, name: string}>, students: array<int, array<string, mixed>>}
     */
    public function officialCells(Instrument $instrument): array
    {
        $context = new InstrumentResultsContext($instrument);
        $bands = $context->bands();
        $threshold = $context->threshold();
        $dimensions = $context->dimensions();

        $domains = array_map(fn (array $domain): array => [
            'key' => 'd'.$domain['id'],
            'id' => (int) $domain['id'],
            'name' => $domain['name'],
        ], $context->touchedDomains());

        $students = [];
        foreach ($this->studentsPayload($instrument, $dimensions, $bands, $threshold) as $student) {
            $students[$student['enrollment_id']] = [
                'status' => $student['status'],
                'status_label' => $student['status_label'],
                'global' => $student['global'],
                'domains' => $student['domains'],
            ];
        }

        return ['domains' => $domains, 'students' => $students];
    }

    /**
     * The threshold the grid's official-classification column needs, without
     * computing the cells themselves — for a caller that only wants the
     * scalar (design addendum §4, the `official.threshold` prop).
     */
    public function officialThreshold(Instrument $instrument): ?string
    {
        return (new InstrumentResultsContext($instrument))->threshold();
    }

    /**
     * The official-statistics gate (design addendum): only a concluded
     * correction (`InstrumentStatus::isConcluded()`) has official numbers.
     * Everything else — still being corrected, not started, or annulled —
     * gets an explanation instead of a computation.
     *
     * @return array{official: bool, status: string, status_label: string, message: string|null}
     */
    protected function availabilityPayload(Instrument $instrument): array
    {
        $status = $instrument->status;
        $official = $status->isConcluded();

        $message = match (true) {
            $official => null,
            $status === InstrumentStatus::Cancelled => 'Este instrumento foi anulado: não tem resultados oficiais.',
            default => 'Os resultados oficiais ficam disponíveis quando a correção deste instrumento estiver concluída. '
                .'Enquanto corrige, a grelha de correção mostra os valores de trabalho, que são provisórios.',
        };

        return [
            'official' => $official,
            'status' => $status->value,
            'status_label' => $status->label(),
            'message' => $message,
        ];
    }

    /**
     * @param  list<AnalysisBand>  $bands
     * @return array<string, mixed>
     */
    protected function contextPayload(InstrumentResultsContext $context, array $bands, ?string $threshold): array
    {
        $instrument = $context->instrument();
        $class = $instrument->schoolClass;
        $scale = $context->scale();

        return [
            'kind' => 'instrument',
            'is_diagnostic' => $context->isDiagnostic(),
            'classificatory' => $context->classificatory(),
            'counts_toward_classification' => $context->countsTowardClassification(),
            'diagnostic_counts_warning' => $context->isDiagnostic() && $context->countsTowardClassification(),
            'instrument' => $context->identification(),
            'class' => ['ulid' => $class->ulid, 'label' => $class->label],
            'period' => ['label' => $instrument->academicPeriod->label],
            'absence_mode' => $context->absenceMode(),
            'absence_mode_label' => $context->absenceModeLabel(),
            'threshold' => $this->thresholdContextPayload($threshold, $scale?->name),
            'scale' => [
                'name' => $scale?->name,
                'has_bands' => $bands !== [],
                'bands' => array_map(fn (AnalysisBand $band) => $this->bandPayload($band), $bands),
            ],
            'domains' => array_map(fn (array $domain) => [
                'key' => 'd'.$domain['id'],
                'id' => $domain['id'],
                'name' => $domain['name'],
                'weight_percent' => $domain['weight_percent'],
            ], $context->touchedDomains()),
            'items_without_domain' => $context->itemsWithoutDomain(),
            'notes' => $context->notes(),
        ];
    }

    /**
     * @return array{value: string|null, label: string|null, explanation: string}
     */
    protected function thresholdContextPayload(?string $threshold, ?string $scaleName): array
    {
        if ($threshold === null) {
            return [
                'value' => null,
                'label' => null,
                'explanation' => 'A escala configurada não define uma fronteira quantitativa inequívoca entre apreciações '
                    .'negativas e não negativas; não é apresentado um limiar.',
            ];
        }

        $label = str_replace('.', ',', $threshold).' %';

        return [
            'value' => $threshold,
            'label' => $label,
            'explanation' => sprintf(
                'Limiar entre apreciações negativas e não negativas da escala «%s»: %s, aplicado ao valor exato.',
                $scaleName ?? '—',
                $label,
            ),
        ];
    }

    /**
     * @param  list<array{key: string, label: string, observations: list<Observation>}>  $dimensions
     * @param  list<AnalysisBand>  $bands
     * @return list<array<string, mixed>>
     */
    protected function studentsPayload(Instrument $instrument, array $dimensions, array $bands, ?string $threshold): array
    {
        $enrollments = ClassCohort::enrollmentsFor($instrument->schoolClass)->values();

        $globalByKey = [];
        $domainByKey = []; // domainKey => [enrollmentKey => Observation]

        foreach ($dimensions as $dimension) {
            if ($dimension['key'] === 'global') {
                foreach ($dimension['observations'] as $observation) {
                    $globalByKey[$observation->key] = $observation;
                }

                continue;
            }

            $domainByKey[$dimension['key']] = [];
            foreach ($dimension['observations'] as $observation) {
                $domainByKey[$dimension['key']][$observation->key] = $observation;
            }
        }

        $students = [];

        foreach ($enrollments as $enrollment) {
            $key = (string) $enrollment->getKey();
            $global = $globalByKey[$key] ?? null;

            $domainsCell = [];
            foreach ($domainByKey as $domainKey => $observations) {
                $observation = $observations[$key] ?? null;
                $domainsCell[$domainKey] = $observation === null ? null : $this->cell($observation, $bands, $threshold);
            }

            $students[] = [
                'enrollment_id' => (int) $enrollment->getKey(),
                'class_number' => $enrollment->class_number,
                'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                'status' => $global?->status->value ?? ObservationStatus::Pending->value,
                'status_label' => $global?->status->label() ?? ObservationStatus::Pending->label(),
                'global' => $global === null
                    ? ['value' => null, 'exact' => null, 'value_precise' => null, 'band' => null, 'below_threshold' => null, 'is_partial' => false]
                    : $this->cell($global, $bands, $threshold),
                'domains' => $domainsCell,
            ];
        }

        return $students;
    }

    /**
     * §Rounding near a boundary: `value_precise` is set only when the 1-decimal
     * display value would fall in a different band than the exact value, or on
     * a different side of the threshold — the case where the grid (1 decimal)
     * and the exact statistic could visually disagree. It is the exact value
     * TRUNCATED (not rounded) to 2 decimals, so it never itself rounds across
     * another boundary. `band` and `below_threshold` are always computed from
     * the exact value, never the display one.
     *
     * @param  list<AnalysisBand>  $bands
     * @return array<string, mixed>
     */
    protected function cell(Observation $observation, array $bands, ?string $threshold): array
    {
        $exact = $observation->exact;

        if ($exact === null) {
            return ['value' => null, 'exact' => null, 'value_precise' => null, 'band' => null, 'below_threshold' => null, 'is_partial' => $observation->partial];
        }

        $exactBand = $this->matchBand($bands, $exact);
        $displayValue = Bc::round(Bc::of($exact), 1, 'half_up');
        $displayBand = $this->matchBand($bands, $displayValue);

        $belowThresholdExact = $threshold === null ? null : Bc::compare(Bc::of($exact), Bc::of($threshold)) < 0;
        $belowThresholdDisplay = $threshold === null ? null : Bc::compare(Bc::of($displayValue), Bc::of($threshold)) < 0;

        $differentBand = ($exactBand?->id) !== ($displayBand?->id);
        $differentSide = $threshold !== null && $belowThresholdExact !== $belowThresholdDisplay;

        return [
            'value' => $displayValue,
            'exact' => $exact,
            'value_precise' => ($differentBand || $differentSide) ? Bc::truncate(Bc::of($exact), 2) : null,
            'band' => $exactBand === null ? null : $this->bandPayload($exactBand),
            'below_threshold' => $belowThresholdExact,
            'is_partial' => $observation->partial,
        ];
    }

    /**
     * @param  list<AnalysisBand>  $bands
     */
    protected function matchBand(array $bands, string $exact): ?AnalysisBand
    {
        foreach ($bands as $band) {
            if (Bc::compare(Bc::of($exact), Bc::of($band->min)) >= 0
                && Bc::compare(Bc::of($exact), Bc::of($band->max)) <= 0) {
                return $band;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function bandPayload(AnalysisBand $band): array
    {
        return [
            'key' => (string) $band->id,
            'code' => $band->code,
            'label' => $band->label,
            'sequence' => $band->sequence,
            'is_negative' => $band->isNegative,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function notePayload(Instrument $instrument): array
    {
        $note = ResultsAnalysisNote::query()
            ->where('instrument_id', $instrument->getKey())
            ->where('context_kind', 'instrument')
            ->first();

        if ($note === null) {
            return ['body' => '', 'lock_version' => 0, 'updated_at' => null, 'updated_by' => null];
        }

        return [
            'body' => (string) $note->body,
            'lock_version' => $note->lock_version,
            'updated_at' => $note->updated_at?->toIso8601String(),
            'updated_by' => optional($note->updater)->name,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function links(Instrument $instrument, bool $includeIndividual): array
    {
        return [
            'grid' => route('instruments.show', $instrument->ulid),
            'results' => route('instruments.results', $instrument->ulid),
            'report' => route('instruments.results.report', $instrument->ulid),
            'report_with_individual' => route('instruments.results.report', $instrument->ulid).'?individual=1',
            'note' => route('instruments.results.note', $instrument->ulid),
        ];
    }
}
