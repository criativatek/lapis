<?php

namespace Tests\Feature\DataImports;

use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\CancelledLessonOccurrence;
use App\Models\ClassGroup;
use App\Models\ClassGroupMembership;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\Organization;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Services\Lessons\ClassAttendanceSummary;
use App\Services\Lessons\LessonAttendanceRoster;
use App\Services\Lessons\StudentAttendanceHistory;
use App\Support\Entitlements\Entitlements;
use App\Support\Import\Backup\BackupSchemaCompatibility;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;
use ZipArchive;

/**
 * Fatia "aulas e assiduidade no backup" (schema_version 9; v10 adds the split keys). Round-trips the
 * whole minimal coherent set docs/backup-schema.md now describes: class
 * groups, memberships, the recurring schedule, a cancelled occurrence,
 * several lessons in different states, their summary/plan, and consolidated
 * + draft attendance — proving a restore reproduces the exact same reads the
 * lessons/attendance screens already give (LessonAttendanceRoster,
 * StudentAttendanceHistory, ClassAttendanceSummary), never a recomputed
 * approximation.
 */
class LessonAttendanceRoundTripTest extends TestCase
{
    use RefreshDatabase;
    use SubscribesOrganizations;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->teacher = User::factory()->create();
    }

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    private function inOrganization(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }

    /**
     * Schema v10: the opaque `split_lesson_key` (T1/T2 slots that are the
     * same lesson) and `lesson_unit_key` (lessons that are the same lesson)
     * travel verbatim into another organization.
     */
    #[Test]
    public function split_lesson_and_lesson_unit_keys_survive_a_restore_into_another_organization(): void
    {
        $class = $this->scenario();
        $splitLessonKey = (string) Str::ulid();
        $lessonUnitKey = (string) Str::ulid();

        $this->inTenant(function () use ($class, $splitLessonKey, $lessonUnitKey): void {
            $slotT1 = RecurringLessonSlot::where('class_id', $class->id)->firstOrFail();
            $slotT1->forceFill(['split_lesson_key' => $splitLessonKey])->save();
            $groupT2 = ClassGroup::create(['class_id' => $class->id, 'label' => 'T2', 'position' => 2]);
            RecurringLessonSlot::create([
                'class_id' => $class->id, 'class_group_id' => $groupT2->id, 'day_of_week' => 4,
                'starts_at' => '10:00:00', 'ends_at' => '11:00:00', 'starts_on' => '2025-09-01',
                'split_lesson_key' => $splitLessonKey,
            ]);
            Lesson::where('class_id', $class->id)->whereIn('lesson_number', [1])
                ->each(fn (Lesson $lesson) => $lesson->forceFill(['lesson_unit_key' => $lessonUnitKey])->save());
        });

        $backup = $this->backupUpload();
        $json = $this->backupJson($backup);
        $this->assertSame(BackupSchemaCompatibility::CURRENT, $json['schema_version']);

        $colleague = User::factory()->create(['email' => 'colega-chaves@example.test']);
        $destination = $colleague->personalOrganization();
        $import = $this->uploadIntoAs($destination, $colleague, $backup);
        $this->confirmAs($destination, $colleague, $import);

        $this->inOrganization($destination, function () use ($splitLessonKey, $lessonUnitKey): void {
            $restoredClass = SchoolClass::firstOrFail();
            $slots = RecurringLessonSlot::where('class_id', $restoredClass->id)->with('classGroup')->get();
            $this->assertCount(2, $slots);
            $this->assertEqualsCanonicalizing(['T1', 'T2'], $slots->map(fn (RecurringLessonSlot $slot): ?string => $slot->classGroup?->label)->all());
            foreach ($slots as $slot) {
                $this->assertSame($splitLessonKey, $slot->split_lesson_key);
            }

            $linked = Lesson::where('class_id', $restoredClass->id)->where('lesson_unit_key', $lessonUnitKey)->count();
            $this->assertSame(2, $linked);
            $this->assertSame(2, Lesson::where('class_id', $restoredClass->id)->whereNull('lesson_unit_key')->count());
        });
    }

    #[Test]
    public function a_v9_backup_without_the_keys_restores_them_as_null(): void
    {
        $this->scenario();
        $backup = $this->backupUpload();
        $legacy = $this->rewriteBackup($backup, function (array $json): array {
            $json['schema_version'] = 9;
            foreach ($json['recurring_lesson_slots'] as $index => $row) {
                unset($json['recurring_lesson_slots'][$index]['split_lesson_key']);
            }
            foreach ($json['lessons'] as $index => $row) {
                unset($json['lessons'][$index]['lesson_unit_key']);
            }

            return $json;
        });

        $colleague = User::factory()->create(['email' => 'colega-v9@example.test']);
        $destination = $colleague->personalOrganization();
        $import = $this->uploadIntoAs($destination, $colleague, $legacy);
        $this->assertSame(9, $import->source_schema_version);
        $this->confirmAs($destination, $colleague, $import);

        $this->inOrganization($destination, function (): void {
            $restoredClass = SchoolClass::firstOrFail();
            $slots = RecurringLessonSlot::where('class_id', $restoredClass->id)->get();
            $lessons = Lesson::where('class_id', $restoredClass->id)->get();
            $this->assertNotEmpty($slots);
            $this->assertCount(4, $lessons);
            $this->assertTrue($slots->every(fn (RecurringLessonSlot $slot): bool => $slot->split_lesson_key === null));
            $this->assertTrue($lessons->every(fn (Lesson $lesson): bool => $lesson->lesson_unit_key === null));
        });
    }

    #[Test]
    public function the_complete_lessons_and_attendance_graph_survives_a_round_trip(): void
    {
        $class = $this->scenario();
        $classUlid = $class->ulid;

        $before = $this->snapshot($class);

        $backup = $this->backupUpload();
        $this->deleteLessonsData($class);
        $import = $this->uploadInto($backup);
        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->teacher->personalOrganization()->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $restoredClass = $this->inTenant(fn (): SchoolClass => SchoolClass::where('ulid', $classUlid)->firstOrFail());
        $after = $this->snapshot($restoredClass);

        $this->assertSame($before, $after);

        // Sem órfãos: cada FK de aulas/assiduidade resolve dentro do
        // conjunto restaurado.
        $this->inTenant(function () use ($restoredClass): void {
            $lessonIds = Lesson::where('class_id', $restoredClass->id)->pluck('id');
            $this->assertSame(0, LessonAttendance::whereIn('lesson_id', $lessonIds)->whereDoesntHave('lesson')->count());
            $this->assertSame(0, LessonSummary::whereIn('lesson_id', $lessonIds)->whereDoesntHave('lesson')->count());
            $this->assertSame(0, LessonPlan::whereIn('lesson_id', $lessonIds)->whereDoesntHave('lesson')->count());
        });

        // A ocorrência cancelada não renasce ao materializar a mesma semana.
        $this->inTenant(function () use ($restoredClass): void {
            $group = ClassGroup::where('class_id', $restoredClass->id)->where('label', 'T1')->firstOrFail();
            $cancelledExists = CancelledLessonOccurrence::where('class_id', $restoredClass->id)
                ->where('class_group_id', $group->id)->exists();
            $this->assertTrue($cancelledExists);

            app(MaterializeLessonsForRange::class)->execute(
                $restoredClass->fresh(),
                CarbonImmutable::parse('2025-10-06', 'Europe/Lisbon'),
                CarbonImmutable::parse('2025-10-12', 'Europe/Lisbon'),
                $this->teacher,
            );

            $stillCancelled = ! Lesson::where('class_id', $restoredClass->id)
                ->where('class_group_id', $group->id)
                ->whereDate('starts_at', '2025-10-09')
                ->exists();
            $this->assertTrue($stillCancelled, 'A aula cancelada não deveria renascer.');
        });
    }

    #[Test]
    public function reimporting_the_same_backup_creates_nothing_new(): void
    {
        $this->scenario();
        $backup = $this->backupUpload();

        $import = $this->uploadInto($backup);
        $preview = $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->teacher->personalOrganization()->id])
            ->get("/data-imports/{$import->ulid}");

        foreach ([
            'class_groups', 'class_group_memberships', 'recurring_lesson_slots',
            'lessons', 'lesson_summaries', 'lesson_plans', 'lesson_attendances',
        ] as $domain) {
            $this->assertSame(0, data_get($preview->viewData('page'), "props.plan.counts.{$domain}.new", -1), $domain);
        }
    }

    #[Test]
    public function a_legacy_backup_without_the_lesson_collections_imports_with_zero_lessons(): void
    {
        $class = $this->scenario();
        $backup = $this->backupUpload();
        $legacy = $this->rewriteBackup($backup, function (array $json): array {
            $json['schema_version'] = 8;
            foreach ([
                'class_groups', 'class_group_memberships', 'recurring_lesson_slots',
                'cancelled_lesson_occurrences', 'lessons', 'lesson_summaries',
                'lesson_plans', 'lesson_attendances',
            ] as $key) {
                unset($json[$key]);
            }

            return $json;
        });

        $this->deleteLessonsData($class);
        $import = $this->uploadInto($legacy);
        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->teacher->personalOrganization()->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $restoredClass = $this->inTenant(fn (): SchoolClass => SchoolClass::where('label', 'like', '7.º A%')->firstOrFail());
        $this->inTenant(function () use ($restoredClass): void {
            $this->assertSame(0, Lesson::where('class_id', $restoredClass->id)->count());
            $this->assertSame(0, ClassGroup::where('class_id', $restoredClass->id)->count());
        });
    }

    /**
     * A malformed row (an attendance status the enum does not know) is
     * rejected at `ValidateBackupPayload` and never reaches the canonical
     * snapshot at all — the SAME behaviour every other domain in this
     * importer already has (`whitelistRows()` drops the row and records a
     * `rowIssue`). NOTE: `rowIssue`s are computed but never persisted or
     * threaded back into `DataImportController::edit()`/`confirm()` (both
     * always call `BuildImportPlan::build()` with an empty `$rowIssues`
     * array) — a pre-existing gap in the whole importer, not something this
     * fatia introduced, so a validation-rejected row surfaces here as "one
     * row fewer restored", never as a visible `invalid` count. What this
     * test pins is the part that IS this fatia's to get right: the bad row
     * does not block or corrupt the three good ones.
     */
    #[Test]
    public function an_invalid_attendance_row_is_dropped_without_blocking_the_others(): void
    {
        $this->scenario();
        $backup = $this->backupUpload();
        $tampered = $this->rewriteBackup($backup, function (array $json): array {
            $json['lesson_attendances'][0]['status'] = 'late';

            return $json;
        });

        $import = $this->uploadInto($tampered);
        $preview = $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->teacher->personalOrganization()->id])
            ->get("/data-imports/{$import->ulid}");

        // Same organization as the source (never deleted), so the three
        // untouched rows match their own ulid and are `existing` — the
        // dropped fourth row simply is not counted anywhere.
        $this->assertSame(3, data_get($preview->viewData('page'), 'props.plan.counts.lesson_attendances.existing', 0));
        $this->assertSame(0, data_get($preview->viewData('page'), 'props.plan.counts.lesson_attendances.invalid', -1));
    }

    /**
     * The common real-world restore: the class, its students and their
     * enrollments were never lost — only the lessons/attendance tier was
     * (or the organization is simply re-importing an older lesson backup
     * over a class it already teaches). `ExecuteDataImport`'s own map for
     * this tier has to resolve `existing` enrollments, not just `new` ones
     * (see the "mapa de inscrições" note in docs/backup-schema.md) — this
     * is the test that would fail if that map only had `new` entries.
     */
    #[Test]
    public function restore_into_an_existing_class_links_attendance_to_existing_enrollments(): void
    {
        $class = $this->scenario();
        $before = $this->snapshot($class);
        $enrollmentIdsBefore = $this->inTenant(fn (): array => Enrollment::where('class_id', $class->id)->pluck('id')->all());

        $backup = $this->backupUpload();
        $this->deleteLessonSideData($class);

        $import = $this->uploadInto($backup);
        $preview = $this->previewAs($this->teacher->personalOrganization(), $this->teacher, $import);

        $this->assertSame(0, data_get($preview->viewData('page'), 'props.plan.counts.enrollments.new', -1));
        $this->assertSame(2, data_get($preview->viewData('page'), 'props.plan.counts.enrollments.existing', 0));
        $this->assertGreaterThan(0, data_get($preview->viewData('page'), 'props.plan.counts.lessons.new', 0));
        $this->assertGreaterThan(0, data_get($preview->viewData('page'), 'props.plan.counts.lesson_attendances.new', 0));

        $this->confirmAs($this->teacher->personalOrganization(), $this->teacher, $import);

        $this->inTenant(function () use ($class, $enrollmentIdsBefore): void {
            $attendances = LessonAttendance::whereHas('lesson', fn ($query) => $query->where('class_id', $class->id))
                ->with('enrollment')->get();
            $this->assertNotEmpty($attendances);

            foreach ($attendances as $attendance) {
                $this->assertContains($attendance->enrollment_id, $enrollmentIdsBefore, 'attendance was linked to a NEW enrollment instead of the original one');
                $this->assertSame($attendance->enrollment->student_id, $attendance->student_id);
            }
        });

        $after = $this->snapshot($class->fresh());
        $this->assertSame($before, $after);
    }

    /**
     * The cross-account case for THIS tier, same shape as
     * `ImportAuthorshipPortabilityTest`/`CrossOrganizationCloneTest`'s own
     * colleague scenarios: a different, genuinely unrelated account
     * confirming the import. `created_by`/`attendance_recorded_by` on
     * lessons, `created_by` on plans, `cancelled_by` on the cancelled
     * occurrence and `updated_by` on attendance all land NULL — never the
     * importer's own id — and the preview still shows the non-blocking
     * notice. The destination is the colleague's OWN personal organization,
     * so `ExecuteDataImport::writeClasses()` attaches them as the class's
     * teacher itself (`$organization->isPersonal()`) — no manual attach
     * needed for the lessons screens to authorize them afterwards.
     */
    #[Test]
    public function an_unresolved_author_restores_lessons_with_null_author_and_a_notice(): void
    {
        $this->scenario();
        $backup = $this->backupUpload();

        $colleague = User::factory()->create(['email' => 'colega-aulas@example.test']);
        $destination = $colleague->personalOrganization();

        $import = $this->uploadIntoAs($destination, $colleague, $backup);
        $page = $this->previewAs($destination, $colleague, $import)->viewData('page');

        $lessonRows = data_get($page, 'props.plan.rows.lessons', []);
        $lessonNotices = collect(is_array($lessonRows) ? $lessonRows : [])->pluck('notice')->filter()->values();
        $this->assertNotEmpty($lessonNotices);
        $this->assertStringContainsString('não pôde ser associada à conta atual', (string) $lessonNotices->first());
        $this->assertTrue(data_get($page, 'props.plan.can_confirm'));

        $this->confirmAs($destination, $colleague, $import);

        $restoredClass = $this->inOrganization($destination, fn (): SchoolClass => SchoolClass::firstOrFail());

        $this->inOrganization($destination, function () use ($restoredClass): void {
            $lessons = Lesson::where('class_id', $restoredClass->id)->get();
            $this->assertNotEmpty($lessons);

            foreach ($lessons as $lesson) {
                $this->assertNull($lesson->created_by);
                $this->assertNull($lesson->attendance_recorded_by);
            }

            $plans = LessonPlan::whereHas('lesson', fn ($query) => $query->where('class_id', $restoredClass->id))->get();
            $this->assertNotEmpty($plans);
            foreach ($plans as $plan) {
                $this->assertNull($plan->created_by);
            }

            $attendances = LessonAttendance::whereHas('lesson', fn ($query) => $query->where('class_id', $restoredClass->id))->get();
            $this->assertNotEmpty($attendances);
            foreach ($attendances as $attendance) {
                $this->assertNull($attendance->updated_by);
            }

            $cancelled = CancelledLessonOccurrence::where('class_id', $restoredClass->id)->firstOrFail();
            $this->assertNull($cancelled->cancelled_by);
        });

        // The colleague really can reach the restored lessons — proof the
        // empty author never made the screens fail closed on their own
        // account's data.
        $taughtLesson = $this->inOrganization($destination, fn (): Lesson => Lesson::where('class_id', $restoredClass->id)
            ->where('status', LessonStatus::Taught->value)->orderBy('starts_at')->firstOrFail());

        $this->actingAs($colleague)->withSession(['organization_id' => $destination->id])
            ->get("/lessons/{$taughtLesson->ulid}")
            ->assertOk();

        $weekOf = $taughtLesson->starts_at->toDateString();
        $this->actingAs($colleague)->withSession(['organization_id' => $destination->id])
            ->get("/lessons?week={$weekOf}")
            ->assertOk();
    }

    /**
     * A `class_group` that already exists locally under the SAME `ulid` but
     * a DIFFERENT label is a `conflict` (§ ordinary rule for every
     * ulid-identified domain) — and everything under it in this same backup
     * (its recurring slot, its lessons, their attendance) must follow it
     * into `invalid`, never quietly fall back to "the whole class" by
     * writing a NULL `class_group_id`/`recurring_lesson_slot_id`. That
     * silent degrade is exactly the bug `WriteLessons::resolveOptionalId()`
     * exists to close.
     */
    #[Test]
    public function a_class_group_in_conflict_blocks_its_lessons_without_degrading_to_whole_class(): void
    {
        $class = $this->scenario();
        $backup = $this->backupUpload();
        $json = $this->backupJson($backup);
        $classGroups = is_array($json['class_groups'] ?? null) ? $json['class_groups'] : [];
        $groupUlid = collect($classGroups)->firstWhere('label', 'T1')['ulid'] ?? null;
        $this->assertIsString($groupUlid);

        // `ulid` is unique across the WHOLE table (§ docs/data-import.md,
        // "Identidade e o ulid global") — freeing it at the source, exactly
        // like `CrossOrganizationCloneTest` already does, is the only way a
        // second row can legitimately claim it elsewhere while the backup
        // itself still names the original.
        $this->inTenant(function () use ($class): void {
            ClassGroup::where('class_id', $class->id)->where('label', 'T1')->firstOrFail()
                ->forceFill(['ulid' => (string) str()->ulid()])->save();
        });

        $destinationTeacher = User::factory()->create(['email' => 'conflito-grupo@example.test']);
        $destination = $destinationTeacher->personalOrganization();

        $this->inOrganization($destination, function () use ($destination, $groupUlid): void {
            ClassGroup::factory()->recycle($destination)->create([
                'ulid' => $groupUlid, 'label' => 'Grupo local diferente',
            ]);
        });

        $import = $this->uploadIntoAs($destination, $destinationTeacher, $backup);
        $page = $this->previewAs($destination, $destinationTeacher, $import)->viewData('page');

        $this->assertGreaterThan(0, data_get($page, 'props.plan.counts.class_groups.conflict', 0));
        $this->assertSame(0, data_get($page, 'props.plan.counts.class_groups.new', -1));

        $this->confirmAs($destination, $destinationTeacher, $import);

        $this->inOrganization($destination, function () use ($groupUlid): void {
            $localGroup = ClassGroup::where('ulid', $groupUlid)->firstOrFail();
            $this->assertSame('Grupo local diferente', $localGroup->label, 'the conflicting local group must never be overwritten');

            // The destination organization also has the dummy class the
            // local conflicting `ClassGroup` factory created for itself —
            // never mistake it for the restored one.
            $restoredClass = SchoolClass::where('label', 'like', '7.º A%')->firstOrFail();

            // The T1 lesson never lands — neither under the conflicting
            // group nor, worse, silently degraded into a whole-class
            // lesson at the same instant.
            $t1LessonStartsAt = '2025-10-16 09:00:00';
            $this->assertFalse(
                Lesson::where('class_id', $restoredClass->id)->where('starts_at', $t1LessonStartsAt)->exists(),
                'the T1 lesson must not exist at all — neither linked to the conflicting group nor degraded to whole-class',
            );

            // The other three lessons (whole-class, old, draft) are
            // unaffected by the group conflict.
            $this->assertSame(3, Lesson::where('class_id', $restoredClass->id)->count());

            // No orphaned recurring slot/attendance tied to the conflicting
            // group either.
            $this->assertSame(0, RecurringLessonSlot::where('class_group_id', $localGroup->id)->count());
        });
    }

    /**
     * Same-class integrity, membership side: a tampered `enrollment_ulid`
     * pointing at a genuinely different class's enrollment. `T1` itself is
     * deleted first so the group is `new` on restore — otherwise its own
     * `existing`-by-ulid match would short-circuit before this row is ever
     * reached, and the point of this test is the class-identity check
     * `BuildLessonsPlan::classifyMemberships()` runs, not a ulid match.
     */
    #[Test]
    public function a_membership_whose_enrollment_belongs_to_another_class_is_invalid_and_never_written(): void
    {
        $class = $this->scenario();
        $otherEnrollment = $this->secondClassEnrollment();
        $backup = $this->backupUpload();
        $tampered = $this->rewriteBackup($backup, function (array $json) use ($otherEnrollment): array {
            $json['class_group_memberships'][0]['enrollment_ulid'] = $otherEnrollment->ulid;

            return $json;
        });

        $this->deleteLessonSideData($class);
        $import = $this->uploadInto($tampered);
        $page = $this->previewAs($this->teacher->personalOrganization(), $this->teacher, $import)->viewData('page');

        $this->assertGreaterThan(0, data_get($page, 'props.plan.counts.class_group_memberships.invalid', 0));
        $this->assertSame(0, data_get($page, 'props.plan.counts.class_group_memberships.new', -1));

        $this->confirmAs($this->teacher->personalOrganization(), $this->teacher, $import);

        $this->inTenant(function () use ($class): void {
            $group = ClassGroup::where('class_id', $class->id)->where('label', 'T1')->first();
            $this->assertNotNull($group);
            $this->assertSame(0, ClassGroupMembership::where('class_group_id', $group->id)->count());
        });
    }

    /**
     * Same-class integrity, attendance side. `T1` is deleted first (same
     * reason as the membership test above): matched-by-ulid rows are only
     * ever diverge-checked on their OWN mutable fields (status here) — a
     * replayed `enrollment_ulid` is irrelevant once a row is identified by
     * its own ulid, exactly like every other domain in this importer. The
     * class-identity check only has something to prove once this row
     * reaches `BuildLessonsPlan` as a genuine candidate for `new`.
     */
    #[Test]
    public function an_attendance_row_whose_enrollment_belongs_to_another_class_is_invalid_and_never_written(): void
    {
        $class = $this->scenario();
        $otherEnrollment = $this->secondClassEnrollment();
        $backup = $this->backupUpload();
        $tampered = $this->rewriteBackup($backup, function (array $json) use ($otherEnrollment): array {
            $json['lesson_attendances'][0]['enrollment_ulid'] = $otherEnrollment->ulid;

            return $json;
        });

        $this->deleteLessonSideData($class);
        $import = $this->uploadInto($tampered);
        $page = $this->previewAs($this->teacher->personalOrganization(), $this->teacher, $import)->viewData('page');

        $this->assertGreaterThan(0, data_get($page, 'props.plan.counts.lesson_attendances.invalid', 0));

        $this->confirmAs($this->teacher->personalOrganization(), $this->teacher, $import);

        $this->inTenant(function () use ($otherEnrollment): void {
            $this->assertSame(0, LessonAttendance::where('enrollment_id', $otherEnrollment->id)->count());
        });
    }

    /**
     * A `present` row is only a legitimate fact once attendance has been
     * consolidated — never on a draft. `T1`'s draft lesson (lesson_number
     * 3, `attendance_recorded_at` NULL) is deleted and restored fresh so
     * its attendance row reaches `BuildLessonsPlan` as `new`, the only path
     * this rule can apply to (an `existing` lesson's attendance never
     * writes a `new` row at all — see the class docblock).
     */
    #[Test]
    public function a_present_row_on_an_unrecorded_lesson_is_invalid_and_never_written(): void
    {
        $class = $this->scenario();
        $backup = $this->backupUpload();
        $tampered = $this->rewriteBackup($backup, function (array $json): array {
            $draftLessonUlid = null;

            foreach ($json['lessons'] as $lesson) {
                if ($lesson['lesson_number'] === 3) {
                    $draftLessonUlid = $lesson['ulid'];
                }
            }

            foreach ($json['lesson_attendances'] as &$attendance) {
                if ($attendance['lesson_ulid'] === $draftLessonUlid) {
                    $attendance['status'] = 'present';
                }
            }

            return $json;
        });

        $this->deleteLessonSideData($class);
        $import = $this->uploadInto($tampered);
        $page = $this->previewAs($this->teacher->personalOrganization(), $this->teacher, $import)->viewData('page');

        $this->assertGreaterThan(0, data_get($page, 'props.plan.counts.lesson_attendances.invalid', 0));

        $this->confirmAs($this->teacher->personalOrganization(), $this->teacher, $import);

        $this->inTenant(function () use ($class): void {
            $draftLesson = Lesson::where('class_id', $class->id)->where('lesson_number', 3)->firstOrFail();
            $this->assertSame(0, LessonAttendance::where('lesson_id', $draftLesson->id)->where('status', AttendanceStatus::Present)->count());
        });
    }

    /**
     * A malformed (present but not a real ulid) `class_group_ulid` makes
     * `ValidateBackupPayload` drop the WHOLE lesson row (§ same discipline
     * as every other required-shape failure) — it never reaches
     * `BuildLessonsPlan` at all, and critically never falls back to
     * `class_group_id = NULL` (a whole-class lesson) either.
     */
    #[Test]
    public function a_lesson_with_a_malformed_class_group_ulid_is_dropped_without_degrading_to_whole_class(): void
    {
        $class = $this->scenario();
        $backup = $this->backupUpload();
        $tampered = $this->rewriteBackup($backup, function (array $json): array {
            foreach ($json['lessons'] as &$lesson) {
                if ($lesson['class_group_ulid'] !== null) {
                    $lesson['class_group_ulid'] = 'not-a-valid-ulid';
                }
            }

            return $json;
        });

        $this->deleteLessonSideData($class);
        $import = $this->uploadInto($tampered);
        $this->confirmAs($this->teacher->personalOrganization(), $this->teacher, $import);

        $this->inTenant(function () use ($class): void {
            $t1LessonStartsAt = '2025-10-16 09:00:00';
            $this->assertFalse(
                Lesson::where('class_id', $class->id)->where('starts_at', $t1LessonStartsAt)->exists(),
                'a lesson with a malformed class_group_ulid must never be written — neither under a group nor degraded to whole-class',
            );
            $this->assertSame(3, Lesson::where('class_id', $class->id)->count());
        });
    }

    /**
     * An isolated second class + enrollment, for the "belongs to another
     * class" adversarial tests — created for the SAME teacher/organization
     * as `scenario()`'s class so it travels in the same export, but
     * otherwise entirely unrelated to it.
     */
    private function secondClassEnrollment(): Enrollment
    {
        return $this->inTenant(function (): Enrollment {
            $organization = $this->teacher->personalOrganization();
            $year = AcademicYear::query()->firstOrFail();
            $subject = Subject::factory()->recycle($organization)->create(['name' => 'Português']);
            $otherClass = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => '9.º B outra turma',
            ]);
            $otherClass->teachers()->attach($this->teacher, ['role' => 'owner']);
            $student = Student::factory()->recycle($organization)->create();
            StudentIdentity::create(['student_id' => $student->id, 'organization_id' => $organization->id, 'display_name' => 'Carla Outra Turma']);

            return Enrollment::factory()->recycle($organization)->create([
                'class_id' => $otherClass->id, 'student_id' => $student->id, 'enrolled_on' => '2025-09-01',
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function backupJson(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file->getPathname()));
        $json = json_decode((string) $zip->getFromName('backup-lapis.json'), true);
        $zip->close();

        return $json;
    }

    /**
     * Builds turma «7.º A» with a T1/T2 split, one recurring slot for T1,
     * one cancelled occurrence of that slot, four lessons in different
     * states, a summary + a plan, and consolidated + draft attendance.
     */
    private function scenario(): SchoolClass
    {
        return $this->inTenant(function (): SchoolClass {
            $organization = $this->teacher->personalOrganization();
            $year = AcademicYear::factory()->recycle($organization)->create([
                'label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31',
            ]);
            $subject = Subject::factory()->recycle($organization)->create(['name' => 'Matemática']);
            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => '7.º A lições',
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            $enrollments = collect(['Ana Aulas', 'Bruno Aulas'])->map(function (string $name) use ($organization, $class): Enrollment {
                $student = Student::factory()->recycle($organization)->create();
                StudentIdentity::create(['student_id' => $student->id, 'organization_id' => $organization->id, 'display_name' => $name]);

                return Enrollment::factory()->recycle($organization)->create([
                    'class_id' => $class->id, 'student_id' => $student->id, 'enrolled_on' => '2025-09-01',
                ]);
            })->values();

            $groupT1 = ClassGroup::create(['class_id' => $class->id, 'label' => 'T1', 'position' => 1]);
            ClassGroupMembership::create([
                'class_group_id' => $groupT1->id, 'enrollment_id' => $enrollments[0]->id, 'effective_from' => '2025-09-01',
            ]);

            $slot = RecurringLessonSlot::create([
                'class_id' => $class->id, 'class_group_id' => $groupT1->id, 'day_of_week' => 4,
                'starts_at' => '09:00:00', 'ends_at' => '10:00:00', 'starts_on' => '2025-09-01',
            ]);

            CancelledLessonOccurrence::create([
                'class_id' => $class->id, 'class_group_id' => $groupT1->id, 'recurring_lesson_slot_id' => $slot->id,
                'occurs_at' => '2025-10-09 09:00:00', 'cancelled_by' => $this->teacher->id,
            ]);

            // Aula da turma inteira, lecionada, com Presente/Falta.
            $wholeClassLesson = Lesson::create([
                'class_id' => $class->id, 'class_group_id' => null, 'starts_at' => '2025-10-02 09:00:00',
                'ends_at' => '2025-10-02 10:00:00', 'lesson_number' => 1, 'status' => LessonStatus::Taught,
                'attendance_recorded_at' => '2025-10-02 10:05:00', 'attendance_recorded_by' => $this->teacher->id,
                'created_by' => $this->teacher->id,
            ]);
            LessonAttendance::create([
                'lesson_id' => $wholeClassLesson->id, 'enrollment_id' => $enrollments[0]->id,
                'student_id' => $enrollments[0]->student_id, 'status' => AttendanceStatus::Present, 'updated_by' => $this->teacher->id,
            ]);
            LessonAttendance::create([
                'lesson_id' => $wholeClassLesson->id, 'enrollment_id' => $enrollments[1]->id,
                'student_id' => $enrollments[1]->student_id, 'status' => AttendanceStatus::Absent, 'updated_by' => $this->teacher->id,
            ]);
            LessonSummary::create([
                'lesson_id' => $wholeClassLesson->id, 'content' => 'Introdução às frações.', 'reviewed_by' => null,
            ]);
            LessonPlan::create([
                'lesson_id' => $wholeClassLesson->id, 'planned_summary' => 'Rever operações com frações.', 'created_by' => $this->teacher->id,
            ]);

            // Aula de T1, lecionada, com o mesmo tempo do horário.
            $groupLesson = Lesson::create([
                'class_id' => $class->id, 'class_group_id' => $groupT1->id, 'recurring_lesson_slot_id' => $slot->id,
                'starts_at' => '2025-10-16 09:00:00', 'ends_at' => '2025-10-16 10:00:00', 'lesson_number' => 1,
                'status' => LessonStatus::Taught, 'attendance_recorded_at' => '2025-10-16 10:05:00',
                'attendance_recorded_by' => $this->teacher->id, 'created_by' => $this->teacher->id,
            ]);
            LessonAttendance::create([
                'lesson_id' => $groupLesson->id, 'enrollment_id' => $enrollments[0]->id,
                'student_id' => $enrollments[0]->student_id, 'status' => AttendanceStatus::Present, 'updated_by' => $this->teacher->id,
            ]);

            // Aula antiga, lecionada, SEM assiduidade registada (histórico
            // anterior à funcionalidade).
            Lesson::create([
                'class_id' => $class->id, 'starts_at' => '2025-09-04 09:00:00', 'ends_at' => '2025-09-04 10:00:00',
                'lesson_number' => 2, 'status' => LessonStatus::Taught, 'created_by' => $this->teacher->id,
            ]);

            // Aula por preparar, com rascunho de falta.
            $draftLesson = Lesson::create([
                'class_id' => $class->id, 'starts_at' => '2025-10-23 09:00:00', 'ends_at' => '2025-10-23 10:00:00',
                'lesson_number' => 3, 'status' => LessonStatus::Preparation, 'created_by' => $this->teacher->id,
            ]);
            LessonAttendance::create([
                'lesson_id' => $draftLesson->id, 'enrollment_id' => $enrollments[1]->id,
                'student_id' => $enrollments[1]->student_id, 'status' => AttendanceStatus::Absent, 'updated_by' => $this->teacher->id,
            ]);

            return $class->fresh();
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(SchoolClass $class): array
    {
        return $this->inTenant(function () use ($class): array {
            $lessons = Lesson::where('class_id', $class->id)->with(['classGroup', 'attendances', 'summary', 'plan'])->orderBy('starts_at')->get();

            $lessonsShape = $lessons->map(fn (Lesson $lesson): array => [
                'starts_at' => $lesson->starts_at->toIso8601String(),
                'status' => $lesson->status->value,
                'lesson_number' => $lesson->lesson_number,
                'group_label' => $lesson->classGroup?->label,
                'attendance_recorded' => $lesson->attendanceRecorded(),
                'attendances' => $lesson->attendances->map(fn (LessonAttendance $attendance): string => $attendance->status->value)->sort()->values()->all(),
                'summary' => $lesson->summary?->content,
                'plan' => $lesson->plan?->planned_summary,
            ])->values()->all();

            $enrollments = Enrollment::where('class_id', $class->id)->with('student.identity')->get();
            $displayName = function (Enrollment $enrollment): string {
                $identity = $enrollment->student?->identity;

                return $identity === null ? '—' : $identity->display_name;
            };

            $rosterAudience = collect($lessons)->mapWithKeys(function (Lesson $lesson) use ($displayName): array {
                $students = app(LessonAttendanceRoster::class)->for($lesson)['students'];

                return [$lesson->starts_at->toIso8601String() => $students->map($displayName)->sort()->values()->all()];
            })->all();

            $history = $enrollments->mapWithKeys(fn (Enrollment $enrollment) => [
                $displayName($enrollment) => app(StudentAttendanceHistory::class)->for($enrollment)['totals'],
            ])->all();

            $summary = app(ClassAttendanceSummary::class)->for($class->fresh());
            $summaryShape = collect($summary['rows'])->map(fn (array $row): array => ['present' => $row['present'], 'absent' => $row['absent'], 'not_recorded' => $row['not_recorded']])->values()->all();

            return [
                'lessons' => $lessonsShape,
                'roster' => $rosterAudience,
                'history' => $history,
                'class_summary' => $summaryShape,
            ];
        });
    }

    /**
     * Everything the lessons tier itself owns — never the class, students
     * or enrollments underneath it.
     */
    private function deleteLessonSideData(SchoolClass $class): void
    {
        $this->inTenant(function () use ($class): void {
            $lessonIds = Lesson::where('class_id', $class->id)->pluck('id');
            LessonAttendance::whereIn('lesson_id', $lessonIds)->delete();
            LessonSummary::whereIn('lesson_id', $lessonIds)->delete();
            LessonPlan::whereIn('lesson_id', $lessonIds)->delete();
            Lesson::whereIn('id', $lessonIds)->delete();
            CancelledLessonOccurrence::where('class_id', $class->id)->delete();
            RecurringLessonSlot::where('class_id', $class->id)->delete();
            ClassGroupMembership::whereHas('classGroup', fn ($query) => $query->where('class_id', $class->id))->delete();
            ClassGroup::where('class_id', $class->id)->delete();
        });
    }

    private function deleteLessonsData(SchoolClass $class): void
    {
        $this->deleteLessonSideData($class);

        $this->inTenant(function () use ($class): void {
            $enrollments = Enrollment::where('class_id', $class->id)->get();
            $studentIds = $enrollments->pluck('student_id');
            Enrollment::where('class_id', $class->id)->delete();
            StudentIdentity::whereIn('student_id', $studentIds)->delete();
            Student::whereIn('id', $studentIds)->delete();
            $class->teachers()->detach();
            $class->delete();
        });
    }

    private function backupUpload(): UploadedFile
    {
        $organization = $this->teacher->personalOrganization();
        $this->actingAs($this->teacher)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $this->teacher->id)->latest('id')->firstOrFail();

        return new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);
    }

    private function uploadInto(UploadedFile $file): DataImport
    {
        return $this->uploadIntoAs($this->teacher->personalOrganization(), $this->teacher, $file);
    }

    private function uploadIntoAs(Organization $organization, User $actor, UploadedFile $file): DataImport
    {
        if (! app(Entitlements::class)->allowsFor($organization, 'data_backup_restore')) {
            $this->subscribeOrganizationTo($organization, 'pro');
        }

        $this->actingAs($actor)->withSession(['organization_id' => $organization->id])
            ->post('/data-imports', ['file' => $file])
            ->assertSessionHasNoErrors();

        return DataImport::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->where('requested_by', $actor->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function confirmAs(Organization $organization, User $actor, DataImport $import): void
    {
        $this->actingAs($actor)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    private function previewAs(Organization $organization, User $actor, DataImport $import): mixed
    {
        return $this->actingAs($actor)->withSession(['organization_id' => $organization->id])
            ->get("/data-imports/{$import->ulid}");
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function rewriteBackup(UploadedFile $file, callable $mutate): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'lapis-lessons-backup-').'.zip';
        copy($file->getPathname(), $copy);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($copy));
        $json = $mutate(json_decode((string) $zip->getFromName('backup-lapis.json'), true));
        $zip->addFromString('backup-lapis.json', (string) json_encode($json));
        $zip->close();

        return new UploadedFile($copy, 'backup.zip', 'application/zip', null, true);
    }
}
