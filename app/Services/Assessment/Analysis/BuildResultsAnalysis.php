<?php

namespace App\Services\Assessment\Analysis;

use App\Domain\Assessment\Analysis\AnalysisBand;
use App\Domain\Assessment\Analysis\DescriptiveReport;
use App\Domain\Assessment\Analysis\Observation;
use App\Domain\Assessment\Analysis\ObservationStatus;
use App\Domain\Assessment\Analysis\ResultsAnalyzer;
use App\Domain\Assessment\Bc;
use App\Models\Instrument;
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
        $class = $instrument->schoolClass;

        $bands = $context->bands();
        $dimensions = $context->dimensions();
        $threshold = ResultsAnalyzer::DEFAULT_THRESHOLD;

        $dimensionPayload = [];
        foreach ($dimensions as $dimension) {
            $dimensionPayload[] = [
                'key' => $dimension['key'],
                'label' => $dimension['label'],
                'analysis' => $this->analyzer->analyse($dimension['observations'], $bands, $threshold),
            ];
        }

        $contextPayload = $this->contextPayload($context, $bands, $threshold);

        $students = $includeIndividual
            ? $this->studentsPayload($instrument, $dimensions, $bands, $threshold)
            : [];

        $report = DescriptiveReport::compose($contextPayload, $dimensionPayload);
        $report['generated_at'] = now()->toIso8601String();

        $note = $this->notePayload($instrument);

        return [
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
     * @param  list<AnalysisBand>  $bands
     * @return array<string, mixed>
     */
    protected function contextPayload(InstrumentResultsContext $context, array $bands, string $threshold): array
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
            'threshold' => ['value' => $threshold, 'label' => str_replace('.', ',', $threshold).' %'],
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
     * @param  list<array{key: string, label: string, observations: list<Observation>}>  $dimensions
     * @param  list<AnalysisBand>  $bands
     * @return list<array<string, mixed>>
     */
    protected function studentsPayload(Instrument $instrument, array $dimensions, array $bands, string $threshold): array
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
                    ? ['value' => null, 'exact' => null, 'band' => null, 'below_threshold' => null, 'is_partial' => false]
                    : $this->cell($global, $bands, $threshold),
                'domains' => $domainsCell,
            ];
        }

        return $students;
    }

    /**
     * @param  list<AnalysisBand>  $bands
     * @return array<string, mixed>
     */
    protected function cell(Observation $observation, array $bands, string $threshold): array
    {
        $exact = $observation->exact;

        if ($exact === null) {
            return ['value' => null, 'exact' => null, 'band' => null, 'below_threshold' => null, 'is_partial' => $observation->partial];
        }

        $band = $this->matchBand($bands, $exact);

        return [
            'value' => Bc::round(Bc::of($exact), 1, 'half_up'),
            'exact' => $exact,
            'band' => $band === null ? null : $this->bandPayload($band),
            'below_threshold' => Bc::compare(Bc::of($exact), Bc::of($threshold)) < 0,
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
