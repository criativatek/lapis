<?php

namespace App\Actions\Evidence;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\EvidenceRecord;
use App\Models\RecordFollowupCase;
use App\Models\RecordFollowupCategory;
use App\Models\RecordFollowupConfirmation;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Evidence\Followup\AssessmentPeriodTimeline;
use App\Services\Evidence\Followup\FollowupWindow;
use App\Services\Evidence\Followup\RecordFollowupCounter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Marcar como registado no Inovar» — the teacher's declaration, never a
 * verification (the application does not see the Inovar), and never anything
 * that creates or changes a pedagogical record.
 *
 * It declares EXACTLY the occurrences the teacher was shown: the request
 * carries their ULIDs and dates, and if the records changed in between (a
 * correction — including a date moved inside the period —, a deletion, a new
 * one) the declaration is refused so the teacher reviews first. One
 * declaration in force per case — a double click finds the one already there
 * instead of stacking a second.
 *
 * Only at three occurrences or more: a declaration below the threshold would
 * take the key's single alert with it when the third occurrence arrives.
 *
 * Locks the class row before the case row, the same order every logbook write
 * uses, so a declaration and a save never wait on each other in a circle.
 */
final class ConfirmRecordFollowup
{
    public function __construct(
        private readonly RecordFollowupCounter $counter,
        private readonly AuditLog $audit,
        private readonly CurrentOrganization $organization,
    ) {}

    /**
     * @param  list<array{ulid: string, date: string}>  $shownOccurrences
     * @return bool true when a new declaration was made; false when one was already in force
     */
    public function __invoke(
        SchoolClass $class,
        Enrollment $enrollment,
        RecordFollowupCategory $category,
        AcademicPeriod $period,
        AssessmentPeriodTimeline $timeline,
        array $shownOccurrences,
        User $teacher,
    ): bool {
        return DB::transaction(function () use ($class, $enrollment, $category, $period, $timeline, $shownOccurrences, $teacher): bool {
            SchoolClass::query()->whereKey($class->id)->lockForUpdate()->firstOrFail();

            $key = [
                'class_id' => $class->id,
                'enrollment_id' => $enrollment->id,
                'academic_period_id' => $period->id,
                'category' => $category->value,
            ];
            $now = now();

            // A case that predates alerts has no row yet: it is created here,
            // WITHOUT an alert — declaring it is not being notified about it.
            DB::table('record_followup_cases')->insertOrIgnore(array_merge($key, [
                'ulid' => (new RecordFollowupCase)->newUniqueId(),
                'organization_id' => $this->organization->id(),
                'alerted_at' => null,
                'alerted_count' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            $case = RecordFollowupCase::query()->where($key)->lockForUpdate()->firstOrFail();

            $inForce = RecordFollowupConfirmation::query()
                ->where('record_followup_case_id', $case->id)
                ->whereNull('undone_at')
                ->first();

            if ($inForce !== null) {
                return false;
            }

            $occurrences = $this->counter->caseOccurrences($class->id, $enrollment->id, $category, FollowupWindow::forPeriod($period, $timeline));

            if ($occurrences->count() < RecordFollowupCategory::ALERT_THRESHOLD) {
                throw ValidationException::withMessages([
                    'occurrences' => __('Só se marca como registado no Inovar um caso com três ou mais ocorrências deste tipo no período.'),
                ]);
            }

            $current = $occurrences
                ->map(fn (EvidenceRecord $record): string => $record->ulid.'|'.$record->occurred_at->format('Y-m-d'))
                ->sort()->values()->all();
            $shown = collect($shownOccurrences)
                ->map(fn (array $occurrence): string => $occurrence['ulid'].'|'.$occurrence['date'])
                ->unique()->sort()->values()->all();

            if ($current !== $shown) {
                throw ValidationException::withMessages([
                    'occurrences' => __('Os registos deste aluno mudaram desde que abriu o detalhe. Reveja as ocorrências antes de marcar como registado no Inovar.'),
                ]);
            }

            RecordFollowupConfirmation::create([
                'record_followup_case_id' => $case->id,
                'confirmed_by' => $teacher->getKey(),
                'confirmed_at' => $now,
                'occurrence_count' => $occurrences->count(),
                'occurrences' => $occurrences
                    ->map(fn (EvidenceRecord $record): array => ['ulid' => $record->ulid, 'date' => $record->occurred_at->format('Y-m-d')])
                    ->values()
                    ->all(),
            ]);

            $this->audit->record(
                'record_followup.confirmed',
                $case,
                $teacher,
                'Marcado como registado no Inovar.',
                [
                    'class_id' => $class->id,
                    'enrollment_id' => $enrollment->id,
                    'academic_period_id' => $period->id,
                    'category' => $category->value,
                    'occurrence_count' => $occurrences->count(),
                    'evidence_record_ids' => $occurrences->pluck('id')->values()->all(),
                ],
            );

            return true;
        });
    }
}
