<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\CalculationOutcome;
use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\ProfileVersionDomain;
use App\Models\Scale;
use App\Models\SchoolClass;
use Illuminate\Support\Collection;

/**
 * Builds the export-neutral evaluation-sheet read model from canonical results.
 *
 * EVERY LEVEL TRAVELS AS BOTH `code` AND `label`, AND THAT IS NOT REDUNDANCY.
 * A level on «Escala 1 a 5» is the pair `3` / «Suficiente», and a pauta names
 * the decision by its CODE — the number is what a pauta of 2.º/3.º ciclo
 * carries, and a qualitative mention in that column names something the
 * document does not say (SUP-2C774B). The label follows so the screen can put
 * it in the cell's title, the same way Classificações already does.
 *
 * THE LABEL IS ALSO WHAT KEEPS OLD HISTORY READABLE. A snapshot is opened by
 * the very same table component as the live sheet, and the snapshots already
 * captured carry `scale_level_label` and no code. Dropping the label here
 * would blank a column in every pauta kept before this change — history has to
 * go on saying what was on screen the day it was kept, so the reader falls
 * back to the label whenever a payload has no code.
 */
class BuildEvaluationSheet
{
    public function __construct(
        protected ClassResultsCalculator $calculator,
        protected CoverageExplanation $coverage,
        protected ScaleProposalResolver $proposals,
    ) {}

    /**
     * @return array{class_id: int, academic_period_id: int, scope: string, domains: list<array<string, mixed>>, students: list<array<string, mixed>>}
     */
    public function for(
        SchoolClass $schoolClass,
        AcademicPeriod $academicPeriod,
        ClassificationScope|string $scope = ClassificationScope::Period,
    ): array {
        $resolvedScope = is_string($scope) ? ClassificationScope::from($scope) : $scope;
        $results = $this->calculator->forScope($schoolClass, $academicPeriod, $resolvedScope);
        $coverage = $this->coverage->forResults($results);
        $profileDomains = $this->profileDomains($schoolClass);
        $profileVersion = $schoolClass->profileVersion;
        $scale = $profileVersion?->scale()->with('levels')->first();
        $roundingMode = $profileVersion === null ? 'half_up' : $profileVersion->rounding_mode;
        $roundingScale = $profileVersion === null ? 0 : $profileVersion->rounding_scale;
        $classifications = $this->classifications($results, $academicPeriod, $resolvedScope);

        $students = [];
        foreach ($results as $result) {
            $enrollment = $result['enrollment'];
            $outcome = $result['outcome'];
            $enrollmentId = (int) $enrollment->getKey();

            $students[] = [
                'enrollment_id' => $enrollmentId,
                'class_number' => $enrollment->class_number,
                'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                'overall' => $this->overall($outcome, $scale, $roundingMode, $roundingScale),
                'domains' => $this->domains($outcome, $profileDomains, $scale, $coverage[$enrollmentId]['domains'] ?? []),
                'classification' => $this->classification($classifications[$enrollmentId] ?? null),
                'coverage' => $coverage[$enrollmentId]['overall'] ?? CoverageExplanation::none(),
            ];
        }

        return [
            'class_id' => (int) $schoolClass->getKey(),
            'academic_period_id' => (int) $academicPeriod->getKey(),
            'scope' => $resolvedScope->value,
            'domains' => array_values($profileDomains->map(fn (ProfileVersionDomain $profileDomain): array => [
                'domain_id' => (int) $profileDomain->domain_id,
                'name' => (string) $profileDomain->domain->name,
                'sequence' => (int) $profileDomain->sequence,
                'weight_percent' => (string) $profileDomain->weight_percent,
            ])->all()),
            'students' => $students,
        ];
    }

    /**
     * @return Collection<int, ProfileVersionDomain>
     */
    protected function profileDomains(SchoolClass $schoolClass): Collection
    {
        if ($schoolClass->assessment_profile_version_id === null) {
            return collect();
        }

        // `profile_version_domains` has no organization_id of its own — it is
        // reached only through `assessment_profile_version_id`, never queried
        // directly by tenant. If that id ever survives a tenant switch (a
        // stale model reused under a different Organization::runFor, as in
        // the cross-tenant test), the eager-loaded `domain` silently comes
        // back null instead of the row disappearing, because `Domain` (unlike
        // this table) *is* organization-scoped. Drop those rows rather than
        // let a null relation reach the caller.
        return ProfileVersionDomain::query()
            ->where('assessment_profile_version_id', $schoolClass->assessment_profile_version_id)
            ->with('domain')
            ->orderBy('sequence')
            ->get()
            ->filter(fn (ProfileVersionDomain $profileDomain): bool => $profileDomain->domain !== null)
            ->values();
    }

    /**
     * @param  list<array{enrollment: Enrollment, outcome: CalculationOutcome}>  $results
     * @return array<int, Classification>
     */
    protected function classifications(array $results, AcademicPeriod $academicPeriod, ClassificationScope $scope): array
    {
        $enrollmentIds = array_map(fn (array $result): int => (int) $result['enrollment']->getKey(), $results);

        if ($enrollmentIds === []) {
            return [];
        }

        $classifications = Classification::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->where('academic_period_id', $academicPeriod->getKey())
            ->where('scope', $scope)
            ->whereNull('superseded_by_id')
            ->with(['proposedScaleLevel', 'finalScaleLevel'])
            ->get();

        $byEnrollment = [];
        foreach ($classifications as $classification) {
            $byEnrollment[(int) $classification->enrollment_id] = $classification;
        }

        return $byEnrollment;
    }

    /**
     * @return array<string, mixed>
     */
    protected function overall(
        CalculationOutcome $outcome,
        ?Scale $scale,
        string $roundingMode,
        int $roundingScale,
    ): array {
        $level = $outcome->scaleLevelId === null ? null : $scale?->levels->firstWhere('id', $outcome->scaleLevelId);
        $proposal = $this->proposals->resolve(
            $scale,
            $outcome->scaleLevelId,
            $outcome->normalizedValue,
            $outcome->proposedValue,
            $roundingMode,
            $roundingScale,
        );
        $scaleValue = $level !== null
            ? ($level->numeric_value === null ? null : (string) $level->numeric_value)
            : $proposal->value;

        return [
            'normalized_value' => $outcome->normalizedValue,
            'scale_value' => $scaleValue,
            'scale_level_id' => $outcome->scaleLevelId,
            'scale_level_code' => $level?->code,
            'scale_level_label' => $level?->label,
            'result_state' => $outcome->resultState,
            'has_coverage_warning' => $outcome->coverageWarning,
        ];
    }

    /**
     * @param  Collection<int, ProfileVersionDomain>  $profileDomains
     * @param  array<int, array<string, mixed>>  $coverage
     * @return list<array<string, mixed>>
     */
    protected function domains(CalculationOutcome $outcome, Collection $profileDomains, ?Scale $scale, array $coverage): array
    {
        $outcomes = collect($outcome->domains)->keyBy('domainId');

        return array_values($profileDomains->map(function (ProfileVersionDomain $profileDomain) use ($outcomes, $scale, $coverage): array {
            $domainOutcome = $outcomes->get($profileDomain->domain_id);

            if ($domainOutcome === null) {
                // The engine never produced an outcome for this domain (e.g. no
                // item allocates to it this period) — every element is missing,
                // not zero (§9 rule 5), so a coverage warning is implied here.
                return [
                    'domain_id' => (int) $profileDomain->domain_id,
                    'name' => (string) $profileDomain->domain->name,
                    'sequence' => (int) $profileDomain->sequence,
                    'normalized_value' => null,
                    'weight_percent_applied' => (string) $profileDomain->weight_percent,
                    'scale_level_id' => null,
                    'scale_level_code' => null,
                    'scale_level_label' => null,
                    'has_coverage_warning' => true,
                    'coverage' => $coverage[$profileDomain->domain_id] ?? CoverageExplanation::none(),
                ];
            }

            $level = $this->proposals->bandFor($scale, $domainOutcome->normalizedValue);

            return [
                'domain_id' => (int) $profileDomain->domain_id,
                'name' => (string) $profileDomain->domain->name,
                'sequence' => (int) $profileDomain->sequence,
                'normalized_value' => $domainOutcome->normalizedValue,
                'weight_percent_applied' => $domainOutcome->weightPercent,
                'scale_level_id' => $level?->id,
                'scale_level_code' => $level?->code,
                'scale_level_label' => $level?->label,
                'has_coverage_warning' => $domainOutcome->coverageWarning,
                'coverage' => $coverage[$profileDomain->domain_id] ?? CoverageExplanation::none(),
            ];
        })->values()->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function classification(?Classification $classification): ?array
    {
        if ($classification === null) {
            return null;
        }

        return [
            'status' => $classification->status->value,
            'proposed_value' => $classification->proposed_value,
            'proposed_scale_level_id' => $classification->proposed_scale_level_id,
            'proposed_scale_level_code' => $classification->proposedScaleLevel?->code,
            'proposed_scale_level_label' => $classification->proposedScaleLevel?->label,
            'final_value' => $classification->final_value,
            'final_scale_level_id' => $classification->final_scale_level_id,
            'final_scale_level_code' => $classification->finalScaleLevel?->code,
            'final_scale_level_label' => $classification->finalScaleLevel?->label,
            'override_reason' => $classification->override_reason,
        ];
    }
}
