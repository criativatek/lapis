<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Domain\Assessment\CalculationOutcome;
use App\Models\AcademicPeriod;
use App\Models\AssessmentProfileVersion;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\ClassProfileMigration;
use App\Models\ProfileVersionDomain;
use App\Models\ProfileVersionStatus;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Assessment\ProfileMigrationException;
use Illuminate\Support\Facades\DB;

/**
 * Moving a class between profile versions, the auditable way (§10.2, A4). The
 * teacher sees what changes — per student, each period's value before and after,
 * computed under the current rule and the target rule over the same raw elements
 * — then confirms with a reason. Only then does the class move and its open
 * proposals refresh; confirmed and published decisions stay as history under the
 * version that produced them (§10.2: never recalculate history without a record).
 */
class MigrateClassProfile
{
    public function __construct(
        protected ClassResultsCalculator $calculator,
        protected ProposeClassifications $proposer,
        protected AuditLog $audit,
        protected ScaleProposalResolver $proposals,
        protected DecisionScaleReader $decisionScales,
        protected SelfAssessmentTemplateProvider $selfAssessments,
    ) {}

    /**
     * The impact document, computed without touching the class. It reflects exactly
     * what a migration does (§10.2): only OPEN proposals recalculate, so a cell is
     * shown as `refreshed` (before/after) only when a proposal exists there;
     * confirmed/published decisions are `kept` unchanged; a cell with no
     * classification is `none` — the migration will not conjure one.
     *
     * WHAT IS SHOWN IS READ ON THE RIGHT SCALE. Every cell carries `before_text`
     * and `after_text`, ready to print: the current proposal read on the scale of
     * the ORIGIN version and the proposal the TARGET version produces read on the
     * target's scale — through ScaleProposalResolver, the one place that
     * translation lives. A kept decision is read on the scale it was taken on.
     * Comparing only the normalized percentages hid the whole change when the
     * scale was the thing that moved (a 78 % proposal becoming a «4 — Bom»).
     *
     * @return array<string, mixed>
     */
    public function preview(SchoolClass $class, AssessmentProfileVersion $toVersion): array
    {
        $fromVersion = $class->profileVersion;

        $periods = AcademicPeriod::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')->get();

        // The live period-scope classifications, keyed enrollment:period — the
        // rows the migration would touch (proposed) or leave (frozen).
        $live = Classification::query()
            ->with('finalScaleLevel')
            ->whereIn('enrollment_id', $class->enrollments()->select('id'))
            ->where('scope', ClassificationScope::Period)
            ->whereNot('status', ClassificationStatus::Superseded)
            ->get()
            ->keyBy(fn (Classification $classification) => $classification->enrollment_id.':'.$classification->academic_period_id);

        // The "after" outcomes under the target version, over the same raw
        // elements — the WHOLE outcome, because reading it on the target's scale
        // needs its level and rounded value as well as the percentage.
        /** @var array<int, array<int, CalculationOutcome>> $after */
        $after = [];
        foreach ($periods as $period) {
            foreach ($this->calculator->forScope($class, $period, ClassificationScope::Period, $toVersion) as $row) {
                $after[$period->id][$row['enrollment']->id] = $row['outcome'];
            }
        }

        $fromScale = $fromVersion?->scale()->with('levels')->first();
        $toScale = $toVersion->scale()->with('levels')->first();

        $rows = [];
        $affected = 0;
        $recalculated = 0;
        $kept = 0;

        // ClassCohort::for() — o resolver único de «que alunos entram na
        // análise desta disciplina» (ver o seu docblock). AttendingOnly: uma
        // migração de perfil recalcula proposta e é avaliação propriamente
        // dita.
        //
        // H5: SEM DATA ÚNICA AQUI DE PROPÓSITO — este preview percorre TODOS
        // os períodos do ano para a mesma matrícula (o `foreach ($periods
        // as $period)` abaixo), não um só período selecionado; não há um
        // «período» para passar a `asOfPeriod()`. Fica «hoje», que é a
        // pergunta que este ecrã de facto faz: «se eu migrasse agora, quem
        // está inscrito nesta disciplina hoje?».
        foreach (ClassCohort::for($class)->attending() as $enrollment) {
            $cells = [];
            $enrollmentChanged = false;

            foreach ($periods as $period) {
                /** @var Classification|null $classification */
                $classification = $live->get($enrollment->id.':'.$period->id);

                if ($classification === null) {
                    $cells[] = ['period_label' => $period->label, 'state' => 'none', 'before' => null, 'after' => null, 'before_text' => null, 'after_text' => null, 'changed' => false];

                    continue;
                }

                if ($classification->status->isFrozen()) {
                    // A confirmed or published decision is history — the migration
                    // leaves it exactly as it is (§10.2). Shown, never changed.
                    // Read on the scale it was TAKEN on, which a migration to
                    // another scale does not change.
                    $decisionText = $this->decisionText($classification);
                    $kept++;

                    $cells[] = ['period_label' => $period->label, 'state' => 'kept', 'before' => $classification->final_value, 'after' => $classification->final_value, 'before_text' => $decisionText, 'after_text' => $decisionText, 'changed' => false];

                    continue;
                }

                // An open proposal: this is what recalculates. Before is what it
                // currently holds; after is the value under the target version.
                $beforeValue = $classification->proposed_normalized_value;
                $afterOutcome = $after[$period->id][$enrollment->id] ?? null;
                $afterValue = $afterOutcome?->normalizedValue;

                $beforeText = $this->currentProposalText($classification, $fromVersion, $fromScale);
                $afterText = $this->outcomeText($afterOutcome, $toVersion, $toScale);

                // Changed when what the teacher would READ changes, or when the
                // percentage underneath does: the old rule, plus the scale it is
                // read on.
                $cellChanged = $beforeText !== $afterText || ! $this->sameValue($beforeValue, $afterValue);

                $recalculated++;
                if ($cellChanged) {
                    $enrollmentChanged = true;
                }

                $cells[] = ['period_label' => $period->label, 'state' => 'refreshed', 'before' => $beforeValue, 'after' => $afterValue, 'before_text' => $beforeText, 'after_text' => $afterText, 'changed' => $cellChanged];
            }

            if ($enrollmentChanged) {
                $affected++;
            }

            $rows[] = [
                'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                'class_number' => $enrollment->class_number,
                'cells' => $cells,
                'changed' => $enrollmentChanged,
            ];
        }

        return [
            'from' => $fromVersion === null ? null : $this->versionSummary($fromVersion, $fromScale),
            'to' => $this->versionSummary($toVersion, $toScale),
            'comparison' => $this->comparison($fromVersion, $toVersion),
            'periods' => $periods->pluck('label')->all(),
            'rows' => $rows,
            'affected_enrollment_count' => $affected,
            'recalculated_result_count' => $recalculated,
            'kept_result_count' => $kept,
            // What happens to the class's self-assessment: it follows the new
            // version when nobody has answered it yet, and stays with the
            // criteria it was filled in with when somebody has.
            'self_assessment' => $this->selfAssessments->impactOf($class, $toVersion),
        ];
    }

    public function migrate(SchoolClass $class, AssessmentProfileVersion $toVersion, string $reason, User $actor, ?User $onBehalfOf = null): ClassProfileMigration
    {
        if ($toVersion->status !== ProfileVersionStatus::Active) {
            throw ProfileMigrationException::notActive();
        }

        return DB::transaction(function () use ($class, $toVersion, $reason, $actor, $onBehalfOf): ClassProfileMigration {
            // Lock the class and re-read its version inside the transaction: two
            // concurrent migrations must not both record "from V1" and race the
            // final FK — the audit chain has to reflect the real sequence.
            $locked = SchoolClass::query()->whereKey($class->getKey())->lockForUpdate()->firstOrFail();
            $fromVersion = $locked->profileVersion;

            if ($fromVersion !== null && $fromVersion->id === $toVersion->id) {
                throw ProfileMigrationException::sameVersion();
            }

            // Preview under the current (locked) version, then move and refresh.
            $preview = $this->preview($locked, $toVersion);

            $locked->update(['assessment_profile_version_id' => $toVersion->id]);
            // The FK changed but the loaded relation is still the old version;
            // point it at the new one so the re-proposal computes under it.
            $locked->setRelation('profileVersion', $toVersion);

            // The self-assessment follows the class now, inside the same
            // transaction and on the record of whoever decided the change —
            // unless it already holds answers, in which case it stays.
            $this->selfAssessments->followMigratedClass($locked, $actor, $onBehalfOf);

            $periods = AcademicPeriod::query()
                ->where('academic_year_id', $locked->academic_year_id)
                ->orderBy('sequence')->get();

            // Refresh only the open proposals the preview promised — never create
            // new ones the teacher did not see (§10.2; frozen decisions untouched).
            foreach ($periods as $period) {
                foreach ([ClassificationScope::Period, ClassificationScope::Accumulated] as $scope) {
                    $this->proposer->forPeriod($locked, $period, $scope, refreshOnly: true);
                }
            }

            $migration = ClassProfileMigration::create([
                'class_id' => $locked->id,
                'from_version_id' => $fromVersion?->id,
                'to_version_id' => $toVersion->id,
                'impact_preview' => $preview,
                // Counts come from the same preview shown and stored, so the header,
                // the record and the toast never disagree.
                'affected_enrollment_count' => $preview['affected_enrollment_count'],
                'recalculated_result_count' => $preview['recalculated_result_count'],
                'confirmed_by' => $actor->id,
                'on_behalf_of_user_id' => $onBehalfOf?->id,
                'confirmed_at' => now(),
                'reason' => $reason,
            ]);

            $this->audit->record(
                'class.profile_migrated',
                $locked,
                $actor,
                "Turma migrada para a versão {$toVersion->version_number}: {$preview['affected_enrollment_count']} alunos afetados.",
                [
                    'from_version_id' => $fromVersion?->id,
                    'to_version_id' => $toVersion->id,
                    'affected_enrollment_count' => $preview['affected_enrollment_count'],
                    'reason' => $reason,
                    'migration_id' => $migration->id,
                    // Acesso técnico (ADR-0014): o ator é o administrador e o
                    // evento diz em nome de quem; o controlador é que decide.
                    ...($onBehalfOf === null ? [] : [
                        'acting_as_user_id' => $onBehalfOf->id,
                        'technical_access' => true,
                    ]),
                ],
            );

            return $migration;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function versionSummary(AssessmentProfileVersion $version, ?Scale $scale): array
    {
        return [
            'version' => $version->version_number,
            'name' => $version->profile->name,
            'scale' => $scale === null ? null : ['name' => (string) $scale->name, 'kind' => (string) $scale->kind],
            'domains' => $version->domains()->with('domain')->get()
                ->map(fn (ProfileVersionDomain $profileDomain): array => [
                    'name' => (string) $profileDomain->domain->name,
                    'weight' => $this->trimZeros((string) $profileDomain->weight_percent),
                ])
                ->values()->all(),
        ];
    }

    /**
     * What differs between the two versions, said once so the screen does not
     * have to work it out: the scale, the domains, their weights and the
     * calculation rules.
     *
     * @return array<string, mixed>
     */
    protected function comparison(?AssessmentProfileVersion $from, AssessmentProfileVersion $to): array
    {
        $toDomains = $to->domains()->with('domain')->get();
        $fromDomains = $from === null ? collect() : $from->domains()->with('domain')->get();

        $fromByDomain = $fromDomains->keyBy('domain_id');
        $toByDomain = $toDomains->keyBy('domain_id');

        // Union by domain: the target's own order first, then what only the
        // origin had.
        $union = [];
        foreach ($toDomains as $profileDomain) {
            $union[(int) $profileDomain->domain_id] = (string) $profileDomain->domain->name;
        }
        foreach ($fromDomains as $profileDomain) {
            $union[(int) $profileDomain->domain_id] ??= (string) $profileDomain->domain->name;
        }

        $domains = [];
        $weightsSame = $from !== null;

        foreach ($union as $domainId => $name) {
            $fromWeight = $fromByDomain->get($domainId)?->weight_percent;
            $toWeight = $toByDomain->get($domainId)?->weight_percent;
            $sameWeight = $fromWeight !== null && $toWeight !== null
                && Bc::compare(Bc::of((string) $fromWeight), Bc::of((string) $toWeight)) === 0;

            if (! $sameWeight) {
                $weightsSame = false;
            }

            $domains[] = [
                'name' => $name,
                'from_weight' => $fromWeight === null ? null : $this->trimZeros((string) $fromWeight),
                'to_weight' => $toWeight === null ? null : $this->trimZeros((string) $toWeight),
                'changed' => ! $sameWeight,
            ];
        }

        $domainsSame = $from !== null
            && $fromByDomain->keys()->sort()->values()->all() === $toByDomain->keys()->sort()->values()->all();

        return [
            'scale_changes' => $from !== null && $from->scale_id !== $to->scale_id,
            'domains_same' => $domainsSame,
            'weights_same' => $domainsSame && $weightsSame,
            'rules_same' => $from !== null && $this->rulesOf($from) === $this->rulesOf($to),
            'domains' => $domains,
        ];
    }

    /**
     * The calculation rules a version carries, in a form two versions can be
     * compared in.
     *
     * @return array<string, mixed>
     */
    protected function rulesOf(AssessmentProfileVersion $version): array
    {
        return $this->sortedKeys([
            'domain_weight_mode' => $version->domain_weight_mode,
            'period_result_mode' => $version->period_result_mode,
            'accumulated_mode' => $version->accumulated_mode,
            'absence_mode' => $version->absence_mode,
            'rounding_mode' => $version->rounding_mode,
            'rounding_scale' => $version->rounding_scale,
            'rounding_stage' => $version->rounding_stage,
            'minimum_rules' => $version->minimum_rules,
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    protected function sortedKeys(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->sortedKeys($value);
            }
        }

        ksort($values);

        return $values;
    }

    /**
     * The open proposal as it stands now, read on the scale of the ORIGIN
     * version — what the teacher sees today, before anything is migrated.
     */
    protected function currentProposalText(Classification $classification, ?AssessmentProfileVersion $fromVersion, ?Scale $fromScale): string
    {
        return $this->proposalText(
            $fromScale,
            $classification->proposed_scale_level_id,
            $classification->proposed_normalized_value,
            $classification->proposed_value,
            $fromVersion->rounding_mode ?? 'half_up',
            (int) ($fromVersion->rounding_scale ?? 0),
        );
    }

    /**
     * The proposal the TARGET version produces, read on the target's scale.
     */
    protected function outcomeText(?CalculationOutcome $outcome, AssessmentProfileVersion $toVersion, ?Scale $toScale): string
    {
        return $this->proposalText(
            $toScale,
            $outcome?->scaleLevelId,
            $outcome?->normalizedValue,
            $outcome?->proposedValue,
            $toVersion->rounding_mode ?? 'half_up',
            (int) ($toVersion->rounding_scale ?? 0),
        );
    }

    /**
     * One proposal as a string ready to print: «78%» on a percentage scale,
     * «4 — Bom» on a scale of levels, the value on an interval, and «—» when
     * there is nothing to say (never a zero).
     */
    protected function proposalText(?Scale $scale, ?int $scaleLevelId, ?string $normalizedValue, ?string $roundedValue, string $roundingMode, int $roundingScale): string
    {
        $proposal = $this->proposals->resolve($scale, $scaleLevelId, $normalizedValue, $roundedValue, $roundingMode, $roundingScale);

        if (! $proposal->isResolved() || $proposal->value === null) {
            return '—';
        }

        if ($proposal->isPercentage) {
            return $proposal->value.'%';
        }

        $level = $scaleLevelId === null ? null : $scale?->levels->firstWhere('id', $scaleLevelId);

        return $level !== null && (string) $level->label !== '' && (string) $level->label !== $proposal->value
            ? $proposal->value.' — '.$level->label
            : $proposal->value;
    }

    /**
     * A frozen decision, read on the scale it was taken on: «3 — Suficiente» for
     * a level, «65%» for a percentage, the bare number on an interval.
     */
    protected function decisionText(Classification $classification): string
    {
        $level = $classification->finalScaleLevel;

        if ($level !== null) {
            $code = (string) $level->code;

            return (string) $level->label !== '' && (string) $level->label !== $code ? $code.' — '.$level->label : $code;
        }

        if ($classification->final_value === null) {
            return '—';
        }

        $text = $this->trimZeros((string) $classification->final_value);

        return $this->decisionScales->decidedScale($classification)?->kind === 'percentage' ? $text.'%' : $text;
    }

    protected function trimZeros(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    protected function sameValue(?string $before, ?string $after): bool
    {
        if ($before === null || $after === null) {
            return $before === $after;
        }

        return Bc::compare(Bc::of($before), Bc::of($after)) === 0;
    }
}
