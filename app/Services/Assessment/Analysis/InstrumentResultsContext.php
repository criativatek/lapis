<?php

namespace App\Services\Assessment\Analysis;

use App\Domain\Assessment\Analysis\AnalysisBand;
use App\Domain\Assessment\Analysis\Observation;
use App\Domain\Assessment\Analysis\ObservationStatus;
use App\Domain\Assessment\Analysis\ScaleThreshold;
use App\Domain\Assessment\CalculationOutcome;
use App\Models\AssessmentProfileVersion;
use App\Models\Domain;
use App\Models\Instrument;
use App\Models\Scale;
use App\Services\Assessment\AssessmentSummaryQuery;
use App\Services\Assessment\ClassCohort;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\InstrumentEligibility;

/**
 * Context A (design spec §2/§4): one instrument's results, read entirely
 * through `ClassResultsCalculator::forInstruments()` — the same engine and
 * the same frozen profile rules Evolução do Aluno and the accumulated
 * breakdown already use (NOT the grid, whose Total is a client-side points
 * indicator — spec §8 Q2). This class never sums a point or weighs a domain; it only reads
 * `CalculationOutcome`/`DomainOutcome` and derives a per-student STATUS from
 * the engine's own explanation (§3.2), never from a second reading of the
 * cells.
 */
class InstrumentResultsContext implements ResultsContext
{
    private const PRECEDENCE = [
        'pending', 'under_review', 'absent', 'absent_justified', 'exempt', 'not_applicable', 'annulled',
    ];

    private const ABSENCE_MODE_LABELS = [
        'exclude_all' => 'ausências excluídas do cálculo',
        'zero_all' => 'ausências contam como zero',
        'zero_unjustified_only' => 'ausências injustificadas contam como zero; justificadas são excluídas',
        'exclude_all_warn' => 'ausências excluídas do cálculo, com aviso de cobertura',
    ];

    /** @var list<array{key: string, label: string, observations: list<Observation>}>|null */
    private ?array $dimensionsCache = null;

    private ?int $itemsWithoutDomain = null;

    /** @var list<array{id: int, name: string, weight_percent: string|null}>|null */
    private ?array $touchedDomains = null;

    public function __construct(private readonly Instrument $instrument)
    {
        $this->instrument->loadMissing(['items.domainAllocations.domain', 'schoolClass.profileVersion.scale.levels', 'academicPeriod', 'type']);
    }

    public function instrument(): Instrument
    {
        return $this->instrument;
    }

    /**
     * @return array<string, mixed>
     */
    public function identification(): array
    {
        return [
            'ulid' => $this->instrument->ulid,
            'title' => $this->instrument->title,
            'applied_on' => $this->instrument->applied_on->toDateString(),
            'status' => $this->instrument->status->value,
            'status_label' => $this->instrument->status->label(),
            'type' => $this->instrument->type?->name,
            'purpose' => $this->instrument->purpose,
            'purpose_label' => AssessmentSummaryQuery::purposeLabel($this->instrument->purpose),
        ];
    }

    public function isDiagnostic(): bool
    {
        return $this->instrument->purpose === 'diagnostic';
    }

    public function classificatory(): bool
    {
        return ! $this->isDiagnostic();
    }

    /**
     * A configuração EFETIVA — um diagnóstico nunca conta, seja qual for o
     * valor gravado (InstrumentEligibility, a mesma regra do motor).
     */
    public function countsTowardClassification(): bool
    {
        return app(InstrumentEligibility::class)->isConfiguredToCount($this->instrument);
    }

    public function absenceMode(): string
    {
        $version = $this->profileVersion();

        return $version === null ? 'exclude_all_warn' : $version->absence_mode;
    }

    public function absenceModeLabel(): string
    {
        return self::ABSENCE_MODE_LABELS[$this->absenceMode()] ?? $this->absenceMode();
    }

    public function itemsWithoutDomain(): int
    {
        if ($this->itemsWithoutDomain === null) {
            $this->itemsWithoutDomain = $this->instrument->items->filter(
                fn ($item) => $item->domainAllocations->isEmpty(),
            )->count();
        }

        return $this->itemsWithoutDomain;
    }

    /**
     * The domains this instrument's items touch, in the class's profile
     * version order.
     *
     * @return list<array{id: int, name: string, weight_percent: string|null}>
     */
    public function touchedDomains(): array
    {
        if ($this->touchedDomains !== null) {
            return $this->touchedDomains;
        }

        $touchedIds = $this->instrument->items
            ->flatMap(fn ($item) => $item->domainAllocations->pluck('domain_id'))
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->all();

        $version = $this->profileVersion();

        if ($version === null || $touchedIds === []) {
            return $this->touchedDomains = [];
        }

        $weights = $version->domains()->pluck('weight_percent', 'domain_id');

        $ordered = $version->domains()
            ->whereIn('domain_id', $touchedIds)
            ->with('domain')
            ->get();

        $result = [];
        foreach ($ordered as $pvd) {
            $weight = $weights->get($pvd->domain_id);

            $result[] = [
                'id' => (int) $pvd->domain_id,
                'name' => (string) $pvd->domain->name,
                'weight_percent' => $weight !== null ? (string) $weight : null,
            ];
        }

        return $this->touchedDomains = $result;
    }

    public function scale(): ?Scale
    {
        return $this->profileVersion()?->scale;
    }

    /**
     * @return list<AnalysisBand>
     */
    public function bands(): array
    {
        $scale = $this->scale();

        if ($scale === null) {
            return [];
        }

        return array_values($scale->levels
            ->filter(fn ($level) => $level->band_min_normalized !== null && $level->band_max_normalized !== null)
            ->sortBy('sequence')
            ->map(fn ($level) => new AnalysisBand(
                id: $level->id,
                code: $level->code,
                label: $level->label,
                sequence: $level->sequence,
                isNegative: (bool) $level->is_negative,
                min: (string) $level->band_min_normalized,
                max: (string) $level->band_max_normalized,
            ))
            ->all());
    }

    /**
     * The scale's own negative/non-negative boundary (design addendum, Q3) —
     * never a universal constant. See `ScaleThreshold` for the derivation
     * rule; null when the scale does not define one unambiguously.
     */
    public function threshold(): ?string
    {
        return ScaleThreshold::from($this->bands());
    }

    /**
     * @return list<array{key: string, label: string, observations: list<Observation>}>
     */
    public function dimensions(): array
    {
        if ($this->dimensionsCache !== null) {
            return $this->dimensionsCache;
        }

        $class = $this->instrument->schoolClass;

        if ($this->profileVersion() === null) {
            return $this->dimensionsCache = [
                ['key' => 'global', 'label' => 'Global', 'observations' => []],
            ];
        }

        $calculator = app(ClassResultsCalculator::class);
        $enrollments = ClassCohort::enrollmentsFor($class)->values();
        $touchedDomains = $this->touchedDomains();

        $globalObservations = [];
        $domainObservations = [];
        foreach ($touchedDomains as $domain) {
            $domainObservations[$domain['id']] = [];
        }

        foreach ($enrollments as $enrollment) {
            /** @var array<int, CalculationOutcome> $outcomes */
            $outcomes = $calculator->forInstruments($class, $enrollment, collect([$this->instrument]));
            $outcome = $outcomes[(int) $this->instrument->getKey()] ?? null;

            if ($outcome === null) {
                continue;
            }

            $key = (string) $enrollment->getKey();

            $globalObservations[] = $this->globalObservation($key, $outcome);

            foreach ($touchedDomains as $domain) {
                $domainObservations[$domain['id']][] = $this->domainObservation($key, $outcome, (int) $domain['id']);
            }
        }

        $dimensions = [
            ['key' => 'global', 'label' => 'Global', 'observations' => $globalObservations],
        ];

        foreach ($touchedDomains as $domain) {
            $dimensions[] = [
                'key' => 'd'.$domain['id'],
                'label' => $domain['name'],
                'observations' => $domainObservations[$domain['id']],
            ];
        }

        return $this->dimensionsCache = $dimensions;
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        $notes = [];

        if ($this->profileVersion() === null) {
            $notes[] = 'Esta turma não tem um perfil de avaliação ativo — não é possível calcular resultados para este instrumento.';

            return $notes;
        }

        $notes[] = 'Os valores apresentados são os do motor de cálculo, com os pesos de domínio do perfil da turma, '
            .'e podem divergir do total de pontos da grelha quando o instrumento toca mais do que um domínio.';

        $notes[] = 'Regra de ausências desta turma: '.$this->absenceModeLabel().'.';

        $threshold = $this->threshold();
        $notes[] = $threshold === null
            ? 'A escala configurada não define uma fronteira quantitativa inequívoca entre apreciações negativas e não '
                .'negativas; não é apresentado um limiar.'
            : 'O limiar de '.str_replace('.', ',', $threshold).' % é aplicado ao valor exato do resultado, antes do '
                .'arredondamento usado para apresentação.';

        if ($this->itemsWithoutDomain() > 0) {
            $count = $this->itemsWithoutDomain();
            $notes[] = $count === 1
                ? '1 item deste instrumento não tem domínio atribuído e não entra no cálculo.'
                : "{$count} itens deste instrumento não têm domínio atribuído e não entram no cálculo.";
        }

        if ($this->isDiagnostic()) {
            $notes[] = 'Finalidade diagnóstica: os resultados servem para identificar potencialidades, dificuldades e '
                .'necessidades de acompanhamento. Os instrumentos de avaliação diagnóstica não contribuem para as médias '
                .'classificativas.';
        }

        return $notes;
    }

    private function profileVersion(): ?AssessmentProfileVersion
    {
        return $this->instrument->schoolClass->profileVersion;
    }

    private function globalObservation(string $key, CalculationOutcome $outcome): Observation
    {
        $excluded = $this->mergedExcluded($outcome->explanation['domains'] ?? []);

        if ($outcome->normalizedValue !== null) {
            return new Observation($key, ObservationStatus::Classified, $outcome->normalizedValue, $this->isPartial($excluded));
        }

        [$status] = $this->deriveStatus($excluded);

        return new Observation($key, $status, null, false);
    }

    private function domainObservation(string $key, CalculationOutcome $outcome, int $domainId): Observation
    {
        $domainOutcome = null;
        $domainExplanation = null;
        foreach ($outcome->domains as $index => $domain) {
            if ($domain->domainId === $domainId) {
                $domainOutcome = $domain;
                $domainExplanation = ($outcome->explanation['domains'] ?? [])[$index] ?? null;

                break;
            }
        }

        if ($domainOutcome === null) {
            return new Observation($key, ObservationStatus::OutOfScope, null, false);
        }

        $excluded = $domainExplanation['excluded'] ?? [];

        if ($domainOutcome->normalizedValue !== null) {
            return new Observation($key, ObservationStatus::Classified, $domainOutcome->normalizedValue, $this->isPartial($excluded));
        }

        [$status] = $this->deriveStatus($excluded);

        return new Observation($key, $status, null, false);
    }

    /**
     * Every exclusion across the domain explanations, NOT deduplicated. Only the
     * set of reasons matters downstream (status precedence, partial, out of
     * scope), and an item code is unique only within its GROUP — two «Q1» in
     * different groups are different items, so keying by code would let one
     * state silently overwrite another.
     *
     * @param  list<array<string, mixed>>  $domainExplanations
     * @return list<array{item: string, reason: string}>
     */
    private function mergedExcluded(array $domainExplanations): array
    {
        $all = [];
        foreach ($domainExplanations as $domainExplanation) {
            foreach ($domainExplanation['excluded'] ?? [] as $excluded) {
                $all[] = $excluded;
            }
        }

        return $all;
    }

    /**
     * @param  list<array{item: string, reason: string}>  $excluded
     */
    private function isPartial(array $excluded): bool
    {
        foreach ($excluded as $item) {
            if (in_array($item['reason'], ['pending', 'under_review'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * §3.2: every item excluded as `not_applicable_to_enrollment` → out of
     * scope. Otherwise, the highest-precedence real state among the excluded
     * items — pending > under_review > absent > absent_justified > exempt >
     * not_applicable > annulled.
     *
     * @param  list<array{item: string, reason: string}>  $excluded
     * @return array{0: ObservationStatus}
     */
    private function deriveStatus(array $excluded): array
    {
        if ($excluded === []) {
            // No item touched this dimension at all for this student — treat
            // as pending rather than inventing a status.
            return [ObservationStatus::Pending];
        }

        $reasons = array_map(fn (array $item) => $item['reason'], $excluded);
        $realReasons = array_values(array_filter($reasons, fn (string $reason) => $reason !== 'not_applicable_to_enrollment'));

        if ($realReasons === []) {
            return [ObservationStatus::OutOfScope];
        }

        foreach (self::PRECEDENCE as $candidate) {
            if (in_array($candidate, $realReasons, true)) {
                return [ObservationStatus::from($candidate)];
            }
        }

        return [ObservationStatus::NotApplicable];
    }
}
