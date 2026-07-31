<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Models\AcademicPeriod;
use App\Models\AssessmentProfileVersion;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\ClassProfileMigration;
use App\Models\ProfileVersionStatus;
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
    ) {}

    /**
     * The impact document, computed without touching the class. It reflects exactly
     * what a migration does (§10.2): only OPEN proposals recalculate, so a cell is
     * shown as `refreshed` (before/after) only when a proposal exists there;
     * confirmed/published decisions are `kept` unchanged; a cell with no
     * classification is `none` — the migration will not conjure one.
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
            ->whereIn('enrollment_id', $class->enrollments()->select('id'))
            ->where('scope', ClassificationScope::Period)
            ->whereNot('status', ClassificationStatus::Superseded)
            ->get()
            ->keyBy(fn (Classification $classification) => $classification->enrollment_id.':'.$classification->academic_period_id);

        // The "after" values under the target version, over the same raw elements.
        $after = [];
        foreach ($periods as $period) {
            foreach ($this->calculator->forScope($class, $period, ClassificationScope::Period, $toVersion) as $row) {
                $after[$period->id][$row['enrollment']->id] = $row['outcome']->normalizedValue;
            }
        }

        $rows = [];
        $affected = 0;
        $recalculated = 0;

        foreach ($class->enrollments()->with('student.identity')->orderBy('class_number')->get() as $enrollment) {
            $cells = [];
            $enrollmentChanged = false;

            foreach ($periods as $period) {
                /** @var Classification|null $classification */
                $classification = $live->get($enrollment->id.':'.$period->id);

                if ($classification === null) {
                    $cells[] = ['period_label' => $period->label, 'state' => 'none', 'before' => null, 'after' => null, 'changed' => false];

                    continue;
                }

                if ($classification->status->isFrozen()) {
                    // A confirmed or published decision is history — the migration
                    // leaves it exactly as it is (§10.2). Shown, never changed.
                    $cells[] = ['period_label' => $period->label, 'state' => 'kept', 'before' => $classification->final_value, 'after' => $classification->final_value, 'changed' => false];

                    continue;
                }

                // An open proposal: this is what recalculates. Before is what it
                // currently holds; after is the value under the target version.
                $beforeValue = $classification->proposed_normalized_value;
                $afterValue = $after[$period->id][$enrollment->id] ?? null;
                $cellChanged = ! $this->sameValue($beforeValue, $afterValue);

                $recalculated++;
                if ($cellChanged) {
                    $enrollmentChanged = true;
                }

                $cells[] = ['period_label' => $period->label, 'state' => 'refreshed', 'before' => $beforeValue, 'after' => $afterValue, 'changed' => $cellChanged];
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
            'from' => $fromVersion === null ? null : ['version' => $fromVersion->version_number, 'name' => $fromVersion->profile->name],
            'to' => ['version' => $toVersion->version_number, 'name' => $toVersion->profile->name],
            'periods' => $periods->pluck('label')->all(),
            'rows' => $rows,
            'affected_enrollment_count' => $affected,
            'recalculated_result_count' => $recalculated,
        ];
    }

    public function migrate(SchoolClass $class, AssessmentProfileVersion $toVersion, string $reason, User $teacher): ClassProfileMigration
    {
        if ($toVersion->status !== ProfileVersionStatus::Active) {
            throw ProfileMigrationException::notActive();
        }

        return DB::transaction(function () use ($class, $toVersion, $reason, $teacher): ClassProfileMigration {
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
                'confirmed_by' => $teacher->id,
                'confirmed_at' => now(),
                'reason' => $reason,
            ]);

            $this->audit->record(
                'class.profile_migrated',
                $locked,
                $teacher,
                "Turma migrada para a versão {$toVersion->version_number}: {$preview['affected_enrollment_count']} alunos afetados.",
                [
                    'from_version_id' => $fromVersion?->id,
                    'to_version_id' => $toVersion->id,
                    'affected_enrollment_count' => $preview['affected_enrollment_count'],
                    'reason' => $reason,
                    'migration_id' => $migration->id,
                ],
            );

            return $migration;
        });
    }

    protected function sameValue(?string $before, ?string $after): bool
    {
        if ($before === null || $after === null) {
            return $before === $after;
        }

        return Bc::compare(Bc::of($before), Bc::of($after)) === 0;
    }
}
