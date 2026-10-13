<?php

namespace Tests\Feature\Evidence\Concerns;

use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\RecordFollowupCase;
use App\Models\RecordFollowupConfirmation;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;

/**
 * The shared world of the logbook follow-up tests (spec 2026-10-12): a teacher
 * with a class in 2026/2027 organised in trimesters, the clock fixed on
 * 12/10/2026 (first period) BEFORE any data is created, and helpers to write
 * records the way the real screens do.
 */
trait BuildsFollowupFixtures
{
    use BuildsLessonFixtures;

    protected AcademicPeriod $firstPeriod;

    protected AcademicPeriod $secondPeriod;

    protected AcademicPeriod $thirdPeriod;

    protected function bootFollowupFixtures(): void
    {
        // Frozen before anything is created — a record or a subscription born
        // with the real clock would drift past the frozen instant.
        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();

        [$this->firstPeriod, $this->secondPeriod, $this->thirdPeriod] = $this->trimesters();
    }

    /**
     * @return list<AcademicPeriod>
     */
    protected function trimesters(): array
    {
        return [
            $this->makePeriod(1, '1.º Período', '2026-09-14', '2026-12-16'),
            $this->makePeriod(2, '2.º Período', '2027-01-04', '2027-03-26'),
            $this->makePeriod(3, '3.º Período', '2027-04-12', '2027-06-15'),
        ];
    }

    protected function makePeriod(int $sequence, string $label, string $from, string $to, ?int $academicYearId = null, AcademicPeriodKind $kind = AcademicPeriodKind::Term): AcademicPeriod
    {
        return $this->inTenant($this->organization, fn (): AcademicPeriod => AcademicPeriod::create([
            'academic_year_id' => $academicYearId ?? $this->schoolClass->academic_year_id,
            'label' => $label,
            'kind' => $kind,
            'sequence' => $sequence,
            'starts_on' => $from,
            'ends_on' => $to,
            'status' => 'draft',
        ]));
    }

    /**
     * A record written straight to the table — the state a test starts from,
     * like a record saved before this version.
     */
    protected function record(Enrollment $enrollment, EvidenceKind $kind, string $date, ?HomeworkStatus $homeworkStatus = null, ?SchoolClass $class = null): EvidenceRecord
    {
        return $this->inTenant($this->organization, fn (): EvidenceRecord => EvidenceRecord::create([
            'class_id' => ($class ?? $this->schoolClass)->id,
            'enrollment_id' => $enrollment->id,
            'occurred_at' => $date,
            'kind' => $kind->value,
            'homework_status' => $kind === EvidenceKind::Homework ? ($homeworkStatus ?? HomeworkStatus::NotDone)->value : null,
            'description' => '',
            'created_by' => $this->teacher->id,
        ]));
    }

    /**
     * POSTs exactly what records/Show.vue sends — every kind-specific field
     * present, null when it does not belong to the kind.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function postRecord(array $overrides, ?SchoolClass $class = null): TestResponse
    {
        return $this->asTeacher()->postJson('/classes/'.($class ?? $this->schoolClass)->ulid.'/records', array_merge([
            'kind' => 'lateness',
            'disciplinary_severity' => null,
            'homework_status' => null,
            'participation_level' => null,
            'activity_evaluation' => null,
            'activity_include_in_report' => null,
            'description' => 'Chegou depois do toque.',
            'occurred_at' => '2026-10-12',
            'enrollment_ids' => [],
            'domain_id' => null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function putRecord(EvidenceRecord $record, array $overrides): TestResponse
    {
        return $this->asTeacher()->putJson('/records/'.$record->ulid, array_merge([
            'kind' => $record->kind->value,
            'disciplinary_severity' => null,
            'homework_status' => $record->homework_status?->value,
            'participation_level' => null,
            'activity_evaluation' => null,
            'activity_include_in_report' => null,
            'description' => $record->description,
            'occurred_at' => $record->occurred_at->format('Y-m-d'),
            'enrollment_id' => $record->enrollment_id,
            'domain_id' => null,
        ], $overrides));
    }

    /**
     * @param  array<int, string|null>  $statuses  enrollment id → status (null = sem informação)
     */
    protected function saveHomework(string $date, array $statuses, ?SchoolClass $class = null): TestResponse
    {
        $rows = [];

        foreach ($statuses as $enrollmentId => $status) {
            $rows[] = ['enrollment_id' => $enrollmentId, 'homework_status' => $status, 'description' => null];
        }

        return $this->asTeacher()->putJson('/classes/'.($class ?? $this->schoolClass)->ulid.'/records/homework-batch', [
            'occurred_at' => $date,
            'rows' => $rows,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function toast(): array
    {
        $flash = session('inertia.flash_data');
        $this->assertIsArray($flash, 'Nenhuma mensagem foi mostrada ao professor.');

        return $flash['toast'];
    }

    protected function alertCount(): int
    {
        return $this->inTenant($this->organization, fn (): int => RecordFollowupCase::query()->whereNotNull('alerted_at')->count());
    }

    protected function caseFor(Enrollment $enrollment, string $category, ?AcademicPeriod $period = null): ?RecordFollowupCase
    {
        return $this->inTenant($this->organization, fn (): ?RecordFollowupCase => RecordFollowupCase::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('category', $category)
            ->where('academic_period_id', ($period ?? $this->firstPeriod)->id)
            ->first());
    }

    protected function confirmations(): int
    {
        return $this->inTenant($this->organization, fn (): int => RecordFollowupConfirmation::query()->count());
    }

    protected function colleagueTeaching(?SchoolClass $class = null): User
    {
        $colleague = User::factory()->create();

        $this->inTenant($this->organization, function () use ($colleague, $class): void {
            $this->organization->members()->attach($colleague, ['joined_at' => now()]);

            if ($class !== null) {
                $class->teachers()->attach($colleague, ['role' => 'owner']);
            }
        });

        return $colleague;
    }

    protected function secondClass(string $label = '8.º B', ?Subject $subject = null): SchoolClass
    {
        return $this->inTenant($this->organization, function () use ($label, $subject): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->schoolClass->academic_year_id,
                'subject_id' => ($subject ?? Subject::factory()->recycle($this->organization)->create())->id,
                'label' => $label,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class->load('subject');
        });
    }
}
