<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Models\AcademicPeriod;
use App\Models\AssessmentProfileVersion;
use App\Models\ClassificationScope;
use App\Models\ClassProfileMigration;
use App\Models\ProfileVersionStatus;
use App\Models\SchoolClass;
use App\Models\User;
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
    ) {}

    /**
     * The impact document, computed without touching the class.
     *
     * @return array<string, mixed>
     */
    public function preview(SchoolClass $class, AssessmentProfileVersion $toVersion): array
    {
        $fromVersion = $class->profileVersion;

        $periods = AcademicPeriod::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')->get();

        $before = [];
        $after = [];
        foreach ($periods as $period) {
            foreach ($this->calculator->forScope($class, $period, ClassificationScope::Period) as $row) {
                $before[$period->id][$row['enrollment']->id] = $row['outcome']->normalizedValue;
            }
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
                $beforeValue = $before[$period->id][$enrollment->id] ?? null;
                $afterValue = $after[$period->id][$enrollment->id] ?? null;
                $cellChanged = ! $this->sameValue($beforeValue, $afterValue);

                if ($afterValue !== null) {
                    $recalculated++;
                }
                if ($cellChanged) {
                    $enrollmentChanged = true;
                }

                $cells[] = [
                    'period_label' => $period->label,
                    'before' => $beforeValue,
                    'after' => $afterValue,
                    'changed' => $cellChanged,
                ];
            }

            if ($enrollmentChanged) {
                $affected++;
            }

            $rows[] = [
                'name' => $enrollment->student->identity->display_name,
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

        $fromVersion = $class->profileVersion;

        if ($fromVersion !== null && $fromVersion->id === $toVersion->id) {
            throw ProfileMigrationException::sameVersion();
        }

        // Build the preview while the class is still on the old version, then move
        // the class and refresh its open proposals under the new one.
        $preview = $this->preview($class, $toVersion);

        return DB::transaction(function () use ($class, $fromVersion, $toVersion, $reason, $teacher, $preview): ClassProfileMigration {
            $class->update(['assessment_profile_version_id' => $toVersion->id]);
            // The FK changed but the loaded relation is still the old version;
            // point it at the new one so the re-proposal computes under it, not
            // the stale cached relation.
            $class->setRelation('profileVersion', $toVersion);

            $periods = AcademicPeriod::query()
                ->where('academic_year_id', $class->academic_year_id)
                ->orderBy('sequence')->get();

            $refreshed = 0;
            foreach ($periods as $period) {
                foreach ([ClassificationScope::Period, ClassificationScope::Accumulated] as $scope) {
                    $counts = $this->proposer->forPeriod($class, $period, $scope);
                    $refreshed += $counts['created'] + $counts['updated'];
                }
            }

            return ClassProfileMigration::create([
                'class_id' => $class->id,
                'from_version_id' => $fromVersion?->id,
                'to_version_id' => $toVersion->id,
                'impact_preview' => $preview,
                'affected_enrollment_count' => $preview['affected_enrollment_count'],
                'recalculated_result_count' => $refreshed,
                'confirmed_by' => $teacher->id,
                'confirmed_at' => now(),
                'reason' => $reason,
            ]);
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
