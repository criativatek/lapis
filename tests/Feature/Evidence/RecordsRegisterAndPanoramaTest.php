<?php

namespace Tests\Feature\Evidence;

use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonStatus;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\RecordFollowupCase;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Usage\UsageEventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Evidence\Concerns\BuildsFollowupFixtures;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

/**
 * «Registos» to register AND to consult (0.165.0): the three tabs, the homework
 * grid of a whole class on a day, and the class panorama with the four
 * indicators — all on the same sources and rules as the follow-up of 0.164.0.
 */
class RecordsRegisterAndPanoramaTest extends TestCase
{
    use BuildsFollowupFixtures, RefreshDatabase, SetsUsageCoverage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFollowupFixtures();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function records(array $query = []): TestResponse
    {
        return $this->asTeacher()->get('/records'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function panorama(array $query = []): array
    {
        $response = $this->records(['tab' => 'panorama', 'class' => $this->schoolClass->ulid, ...$query])->assertOk();
        $panorama = $response->inertiaProps('panorama');
        $this->assertIsArray($panorama);

        return $panorama;
    }

    /**
     * @param  array<string, mixed>  $panorama
     * @return array<string, mixed>
     */
    private function rowOf(array $panorama, Enrollment $enrollment): array
    {
        foreach ($panorama['rows'] as $row) {
            if ($row['ulid'] === $enrollment->ulid) {
                return $row;
            }
        }

        $this->fail("{$enrollment->ulid} não está no panorama.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function grid(string $date, ?SchoolClass $class = null): array
    {
        return $this->asTeacher()
            ->getJson('/classes/'.($class ?? $this->schoolClass)->ulid.'/records/homework-batch?occurred_at='.$date)
            ->assertOk()
            ->json('enrollments');
    }

    private function taughtLesson(string $startsAt, array $absent, array $present = [], bool $consolidated = true): Lesson
    {
        $lesson = $this->makeLesson([
            'starts_at' => $startsAt,
            'status' => LessonStatus::Taught,
            'outcome' => 'taught',
            'attendance_recorded_at' => $consolidated ? $startsAt : null,
        ]);

        $this->inTenant($this->organization, function () use ($lesson, $absent, $present): void {
            foreach ([['absent', $absent], ['present', $present]] as [$status, $enrollments]) {
                foreach ($enrollments as $enrollment) {
                    LessonAttendance::create(['lesson_id' => $lesson->id, 'enrollment_id' => $enrollment->id, 'student_id' => $enrollment->student_id, 'status' => $status]);
                }
            }
        });

        return $lesson;
    }

    // --- Os três separadores e a turma ----------------------------------------------

    #[Test]
    public function the_menu_opens_on_registering_tpc_and_asks_for_the_class_without_picking_one(): void
    {
        $this->enroll('Ana Costa', ['class_number' => 1]);

        $response = $this->records()->assertOk();

        $this->assertSame('tpc', $response->inertiaProps('filters.tab'));
        $this->assertNull($response->inertiaProps('register.class'));
        $this->assertSame('2026-10-12', $response->inertiaProps('register.date'));
        // Nothing of the search is computed, and no history is ever listed.
        $this->assertSame([], $response->inertiaProps('tiles'));
        $this->assertNull($response->inertiaProps('results'));
        $this->assertNull($response->inertiaProps('panorama'));
    }

    #[Test]
    public function the_class_chosen_is_reused_for_the_session_and_never_one_of_another_year(): void
    {
        $this->records(['tab' => 'tpc', 'class' => $this->schoolClass->ulid])->assertOk();

        $this->records(['tab' => 'panorama'])
            ->assertRedirect('/records?tab=panorama&class='.$this->schoolClass->ulid);

        // A class remembered from another year is forgotten, never reused.
        $this->withSession(['records.class' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']);
        $response = $this->records(['tab' => 'tpc'])->assertOk();
        $this->assertNull($response->inertiaProps('register.class'));
    }

    #[Test]
    public function the_search_state_in_the_url_opens_the_search_tab(): void
    {
        $this->assertSame('search', $this->records(['view' => 'lateness'])->inertiaProps('filters.tab'));
        $this->assertSame('search', $this->records(['searched' => '1'])->inertiaProps('filters.tab'));
        $this->assertCount(4, $this->records(['tab' => 'search'])->inertiaProps('tiles'));
    }

    #[Test]
    public function the_day_proposed_is_today_in_lisbon_summer_and_winter_and_a_chosen_day_is_kept(): void
    {
        // 00:30 in Lisbon summer time is still the previous day in UTC.
        $this->travelTo(Carbon::parse('2026-07-15 00:30:00', 'Europe/Lisbon'));
        $this->assertSame('2026-07-15', $this->records(['tab' => 'tpc'])->inertiaProps('register.date'));

        $this->travelTo(Carbon::parse('2027-01-15 00:30:00', 'Europe/Lisbon'));
        $this->assertSame('2027-01-15', $this->records(['tab' => 'tpc'])->inertiaProps('register.date'));

        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
        $this->assertSame('2026-10-01', $this->records(['tab' => 'tpc', 'date' => '2026-10-01'])->inertiaProps('register.date'));
        $this->assertSame('2026-10-12', $this->records(['tab' => 'tpc', 'date' => '2026-02-30'])->inertiaProps('register.date'));
    }

    // --- A grelha de TPC ----------------------------------------------------------------

    #[Test]
    public function the_grid_lists_every_student_of_the_day_by_number_with_a_stable_tie_break(): void
    {
        $this->enroll('Rui Sousa', ['class_number' => 3]);
        $this->enroll('Ana Costa', ['class_number' => 1]);
        $this->enroll('Zé Sem Número', ['class_number' => null]);
        $this->enroll('Bia Sem Número', ['class_number' => null]);
        $this->enroll('Eva Lima', ['class_number' => 2]);

        $rows = $this->grid('2026-10-12');

        $this->assertSame(['Ana Costa', 'Eva Lima', 'Rui Sousa', 'Bia Sem Número', 'Zé Sem Número'], array_column($rows, 'name'));
        $this->assertSame([1, 2, 3, null, null], array_column($rows, 'number'));
        // Nobody presumed: no marking, no state.
        $this->assertSame([null, null, null, null, null], array_column($rows, 'homework_status'));
        $this->assertSame([true, true, true, true, true], array_column($rows, 'editable'));
    }

    #[Test]
    public function the_grid_follows_who_was_in_the_class_that_day(): void
    {
        $present = $this->enroll('Ana Costa', ['class_number' => 1]);
        $this->enroll('Entrou Depois', ['class_number' => 2, 'enrolled_on' => '2026-10-20']);
        $left = $this->enroll('Saiu Depois', ['class_number' => 3, 'status' => EnrollmentStatus::Left, 'left_on' => '2026-10-15']);
        $this->enroll('Ambígua', ['class_number' => 4, 'status' => EnrollmentStatus::TransferredOut]);
        $leftWithMarking = $this->enroll('Saiu Com Marcação', ['class_number' => 5, 'status' => EnrollmentStatus::Left, 'left_on' => '2026-10-15']);
        $this->record($leftWithMarking, EvidenceKind::Homework, '2026-10-12', HomeworkStatus::Done);

        $response = $this->asTeacher()->getJson("/classes/{$this->schoolClass->ulid}/records/homework-batch?occurred_at=2026-10-12")->assertOk();
        $rows = collect($response->json('enrollments'))->keyBy('ulid');

        $this->assertTrue($rows->has($present->ulid));
        $this->assertFalse($rows->contains('name', 'Entrou Depois'));
        $this->assertFalse($rows->contains('name', 'Ambígua'));
        $this->assertSame(1, $response->json('excluded_without_left_on'));
        // Was there that day, is not any more: read-only without a marking…
        $this->assertFalse($rows[$left->ulid]['editable']);
        $this->assertSame('left', $rows[$left->ulid]['locked_reason']);
        // …and a marking that already exists stays correctable.
        $this->assertTrue($rows[$leftWithMarking->ulid]['editable']);
        $this->assertSame('done', $rows[$leftWithMarking->ulid]['homework_status']);
    }

    #[Test]
    public function the_search_filters_never_hide_anyone_in_the_grid(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);
        $this->enroll('Rui Sousa', ['class_number' => 2]);

        $rows = $this->asTeacher()
            ->getJson("/classes/{$this->schoolClass->ulid}/records/homework-batch?occurred_at=2026-10-12&student={$ana->ulid}&kind=lateness")
            ->assertOk()
            ->json('enrollments');

        $this->assertCount(2, $rows);

        // Going from a search on one student to the grid drops the student.
        $response = $this->records(['tab' => 'tpc', 'class' => $this->schoolClass->ulid, 'student' => $ana->ulid])->assertOk();
        $this->assertSame($this->schoolClass->ulid, $response->inertiaProps('register.class.ulid'));
    }

    #[Test]
    public function two_homework_records_of_one_day_are_never_merged_nor_written_through_the_grid(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);
        $this->record($ana, EvidenceKind::Homework, '2026-10-12', HomeworkStatus::Done);
        $this->record($ana, EvidenceKind::Homework, '2026-10-12', HomeworkStatus::NotDone);

        $row = $this->grid('2026-10-12')[0];
        $this->assertSame(2, $row['record_count']);
        $this->assertFalse($row['editable']);
        $this->assertSame('duplicate', $row['locked_reason']);
        $this->assertNull($row['homework_status']);

        $this->saveHomework('2026-10-12', [$ana->id => 'partially_done'])->assertStatus(422)->assertJsonValidationErrors('rows');

        $this->inTenant($this->organization, fn () => $this->assertEqualsCanonicalizing(
            ['done', 'not_done'],
            EvidenceRecord::query()->where('enrollment_id', $ana->id)->pluck('homework_status')->map(fn (HomeworkStatus $status): string => $status->value)->all(),
        ));
    }

    #[Test]
    public function a_new_marking_needs_the_student_in_the_class_that_day_but_a_correction_is_never_refused(): void
    {
        $late = $this->enroll('Entrou Depois', ['class_number' => 1, 'enrolled_on' => '2026-10-10']);
        $this->saveHomework('2026-10-05', [$late->id => 'done'])->assertStatus(422)->assertJsonValidationErrors('rows');

        $left = $this->enroll('Saiu', ['class_number' => 2]);
        $this->record($left, EvidenceKind::Homework, '2026-10-05', HomeworkStatus::NotDone);
        $this->inTenant($this->organization, fn () => $left->update(['status' => EnrollmentStatus::Left, 'left_on' => '2026-10-08']));

        $this->saveHomework('2026-10-05', [$left->id => 'done'])->assertRedirect();
        $this->inTenant($this->organization, fn () => $this->assertSame(HomeworkStatus::Done, EvidenceRecord::query()->where('enrollment_id', $left->id)->sole()->homework_status));
    }

    #[Test]
    public function saving_the_same_markings_again_changes_nothing_and_counts_nothing(): void
    {
        $this->startUsageCollectionAt('2026-10-01 00:00:00');
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);
        $rui = $this->enroll('Rui Sousa', ['class_number' => 2]);

        $this->saveHomework('2026-10-12', [$ana->id => 'done', $rui->id => 'not_done'])->assertRedirect();
        $this->assertSame('Trabalho de casa guardado.', $this->toast()['message']);

        $this->saveHomework('2026-10-12', [$ana->id => 'done', $rui->id => 'not_done'])->assertRedirect();
        $this->assertSame('Não havia alterações por guardar.', $this->toast()['message']);

        $this->inTenant($this->organization, fn () => $this->assertSame(2, EvidenceRecord::query()->count()));
        $this->assertSame(1, UsageEvent::query()->where('event', UsageEventType::HomeworkBatchSaved->value)->count());
        $this->assertSame(2, UsageEvent::query()->where('event', UsageEventType::HomeworkBatchSaved->value)->sole()->item_count);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'record.homework_batch_updated')->count());
    }

    #[Test]
    public function only_not_done_counts_and_a_correction_moves_the_count_and_the_case_without_a_second_alert(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);

        $this->saveHomework('2026-10-01', [$ana->id => 'not_done']);
        $this->saveHomework('2026-10-02', [$ana->id => 'partially_done']);
        $this->saveHomework('2026-10-05', [$ana->id => 'not_done']);
        $this->saveHomework('2026-10-06', [$ana->id => 'done']);
        $this->assertSame(0, $this->alertCount());

        $this->saveHomework('2026-10-07', [$ana->id => 'not_done']);
        $this->assertSame(1, $this->alertCount());
        $this->assertSame(3, $this->rowOf($this->panorama(), $ana)['counts']['homework_not_done']);
        $this->assertSame('pending', $this->rowOf($this->panorama(), $ana)['statuses']['homework_not_done']);

        // Corrected to «Realizou»: the count falls, the case is no longer «por tratar».
        $this->saveHomework('2026-10-07', [$ana->id => 'done']);
        $row = $this->rowOf($this->panorama(), $ana);
        $this->assertSame(2, $row['counts']['homework_not_done']);
        $this->assertNull($row['statuses']['homework_not_done']);
        $this->assertSame(['done' => 2, 'partially_done' => 1, 'not_done' => 2, 'verified' => 5], $row['homework']);

        // Back to «Não realizou»: the same case, never a second alert.
        $this->saveHomework('2026-10-07', [$ana->id => 'not_done']);
        $this->assertSame(1, $this->alertCount());
        $this->inTenant($this->organization, fn () => $this->assertSame(1, RecordFollowupCase::query()->count()));
    }

    // --- O panorama ---------------------------------------------------------------------

    #[Test]
    public function the_panorama_shows_every_student_with_the_four_indicators_and_agrees_with_the_cards(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);
        $rui = $this->enroll('Rui Sousa', ['class_number' => 2]);
        $eva = $this->enroll('Eva Lima', ['class_number' => 3]);

        foreach (['2026-10-01', '2026-10-02', '2026-10-05'] as $date) {
            $this->record($ana, EvidenceKind::Homework, $date, HomeworkStatus::NotDone);
        }
        $this->record($rui, EvidenceKind::Homework, '2026-10-01', HomeworkStatus::Done);
        $this->record($ana, EvidenceKind::Lateness, '2026-10-06');
        $this->record($rui, EvidenceKind::MissingMaterial, '2026-10-06');
        $this->record($rui, EvidenceKind::MissingMaterial, '2026-10-07');
        $this->taughtLesson('2026-10-08 08:30:00', [$ana], [$rui, $eva]);
        $this->taughtLesson('2026-10-08 10:30:00', [$ana, $rui], [$eva]);

        $panorama = $this->panorama();

        $this->assertSame([$ana->ulid, $rui->ulid, $eva->ulid], array_column($panorama['rows'], 'ulid'));
        $this->assertSame(['homework_not_done' => 3, 'missing_material' => 0, 'lateness' => 1, 'absences' => 2], $this->rowOf($panorama, $ana)['counts']);
        $this->assertSame(1, $this->rowOf($panorama, $ana)['absence_days']);
        $this->assertSame(['homework_not_done' => 0, 'missing_material' => 2, 'lateness' => 0, 'absences' => 1], $this->rowOf($panorama, $rui)['counts']);
        // Eva has nothing: still in the table, «—» where nothing was marked.
        $this->assertSame(['homework_not_done' => null, 'missing_material' => 0, 'lateness' => 0, 'absences' => 0], $this->rowOf($panorama, $eva)['counts']);
        $this->assertSame(['homework_not_done' => 'no_data'], $this->rowOf($panorama, $eva)['missing']);
        $this->assertFalse($this->rowOf($panorama, $eva)['has_occurrences']);

        // A column summed is the card's number for the same class and window.
        $tiles = collect($this->records(['tab' => 'search', 'class' => $this->schoolClass->ulid])->inertiaProps('tiles'))->keyBy('category');
        $this->assertSame($tiles['homework_not_done']['occurrences'], $panorama['totals']['homework_not_done']);
        $this->assertSame($tiles['missing_material']['occurrences'], $panorama['totals']['missing_material']);
        $this->assertSame($tiles['lateness']['occurrences'], $panorama['totals']['lateness']);
        $this->assertSame($tiles['absences']['occurrences'], $panorama['totals']['absences']);

        $this->assertSame(['is_period' => true, 'attendance_applicable' => true, 'homework_days' => 3, 'lessons_counted' => 2, 'lessons_not_recorded' => 0], $panorama['meta']);
    }

    #[Test]
    public function zero_no_data_and_not_applicable_are_said_apart(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);
        $rui = $this->enroll('Rui Sousa', ['class_number' => 2]);
        $this->taughtLesson('2026-10-08 08:30:00', [], [$ana]);
        // A lesson still without attendance is not an absence of anyone.
        $this->taughtLesson('2026-10-09 08:30:00', [$rui], [], consolidated: false);

        $panorama = $this->panorama();
        $this->assertSame(0, $this->rowOf($panorama, $ana)['counts']['absences']);
        $this->assertNull($this->rowOf($panorama, $rui)['counts']['absences']);
        $this->assertSame('no_data', $this->rowOf($panorama, $rui)['missing']['absences']);
        $this->assertSame(1, $panorama['meta']['lessons_not_recorded']);

        // Without the lessons module there is no attendance to read at all.
        $this->inTenant($this->organization, function (): void {
            OrganizationSubscription::withoutGlobalScope('organization')->where('organization_id', $this->organization->id)->delete();
            OrganizationSubscription::withoutGlobalScope('organization')->create([
                'organization_id' => $this->organization->id,
                'plan_id' => Plan::query()->where('key', 'base')->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subMonth(),
            ]);
        });
        app(Entitlements::class)->flush();

        $panorama = $this->panorama();
        $this->assertNull($this->rowOf($panorama, $ana)['counts']['absences']);
        $this->assertSame('not_applicable', $this->rowOf($panorama, $ana)['missing']['absences']);
        $this->assertFalse($panorama['meta']['attendance_applicable']);
    }

    #[Test]
    public function the_panorama_opens_on_the_current_period_and_never_assumes_the_whole_year(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);
        $this->record($ana, EvidenceKind::Lateness, '2026-09-20');
        $this->record($ana, EvidenceKind::Lateness, '2027-01-10');

        $response = $this->records(['tab' => 'panorama', 'class' => $this->schoolClass->ulid])->assertOk();
        $this->assertSame($this->firstPeriod->ulid, $response->inertiaProps('filters.period'));
        $this->assertSame(1, $response->inertiaProps('panorama.totals.lateness'));

        // The second period, explicitly; and a date range spanning both.
        $this->assertSame(1, $this->panorama(['period' => $this->secondPeriod->ulid])['totals']['lateness']);
        $this->assertSame(2, $this->panorama(['mode' => 'range', 'from' => '2026-09-01', 'to' => '2027-01-31'])['totals']['lateness']);

        // In the holidays no period applies: nothing is computed until one is chosen.
        $this->travelTo(Carbon::parse('2026-12-21 10:00:00', 'Europe/Lisbon'));
        $holiday = $this->records(['tab' => 'panorama', 'class' => $this->schoolClass->ulid])->assertOk();
        $this->assertNull($holiday->inertiaProps('panorama'));
        $this->assertTrue($holiday->inertiaProps('context.needs_window'));
    }

    #[Test]
    public function the_panorama_reflects_corrections_and_deletions_at_once_and_ignores_the_search_student(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);
        $rui = $this->enroll('Rui Sousa', ['class_number' => 2]);
        $late = $this->record($ana, EvidenceKind::Lateness, '2026-10-06');
        $this->record($rui, EvidenceKind::Lateness, '2026-10-07');

        $panorama = $this->panorama(['student' => $ana->ulid]);
        $this->assertCount(2, $panorama['rows']);
        $this->assertSame(2, $panorama['totals']['lateness']);

        $this->inTenant($this->organization, fn () => $late->delete());
        $this->assertSame(0, $this->rowOf($this->panorama(), $ana)['counts']['lateness']);
    }

    #[Test]
    public function the_panorama_carries_the_status_of_each_case(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);

        foreach (['2026-10-01', '2026-10-05', '2026-10-12'] as $date) {
            $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => $date])->assertRedirect();
        }

        $this->assertSame('pending', $this->rowOf($this->panorama(), $ana)['statuses']['lateness']);

        $occurrences = $this->inTenant($this->organization, fn () => EvidenceRecord::query()->where('enrollment_id', $ana->id)->orderBy('occurred_at')->get()
            ->map(fn (EvidenceRecord $record): array => ['ulid' => $record->ulid, 'date' => $record->occurred_at->format('Y-m-d')])->all());
        $this->asTeacher()->from('/records')->post('/records/acompanhamento/confirmacoes', [
            'class' => $this->schoolClass->ulid, 'student' => $ana->ulid, 'category' => 'lateness', 'period' => $this->firstPeriod->ulid, 'occurrences' => $occurrences,
        ])->assertRedirect();

        $this->assertSame('registered', $this->rowOf($this->panorama(), $ana)['statuses']['lateness']);
    }

    #[Test]
    public function the_homework_detail_says_what_was_marked_and_the_days_without_a_marking(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);
        $rui = $this->enroll('Rui Sousa', ['class_number' => 2]);
        $this->saveHomework('2026-10-01', [$ana->id => 'not_done', $rui->id => 'done']);
        $this->saveHomework('2026-10-02', [$ana->id => 'partially_done', $rui->id => 'done']);
        $this->saveHomework('2026-10-05', [$rui->id => 'done']);

        $detail = $this->records([
            'tab' => 'panorama', 'class' => $this->schoolClass->ulid,
            'case_class' => $this->schoolClass->ulid, 'case_student' => $ana->ulid, 'case_category' => 'homework_not_done',
        ])->inertiaProps('openCase.homework');

        $this->assertSame(['done' => 0, 'partially_done' => 1, 'not_done' => 1, 'verified' => 2, 'class_days' => 3, 'unmarked_days' => 1], $detail);
    }

    #[Test]
    public function the_panorama_is_read_only_for_whoever_may_see_the_class_and_found_nowhere_else(): void
    {
        $this->enroll('Ana Costa', ['class_number' => 1]);

        // A support session reads it.
        $this->withSession(['impersonator_id' => 999]);
        $this->assertNotNull($this->records(['tab' => 'panorama', 'class' => $this->schoolClass->ulid])->assertOk()->inertiaProps('panorama'));
        $this->flushSession();

        // A colleague's class is not found.
        $colleague = $this->colleagueTeaching();
        $foreign = $this->inTenant($this->organization, function () use ($colleague): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->schoolClass->academic_year_id,
                'subject_id' => Subject::factory()->recycle($this->organization)->create()->id,
            ]);
            $class->teachers()->attach($colleague, ['role' => 'owner']);

            return $class;
        });
        $this->records(['tab' => 'panorama', 'class' => $foreign->ulid])->assertNotFound();
        $this->asTeacher()->getJson("/classes/{$foreign->ulid}/records/homework-batch?occurred_at=2026-10-12")->assertForbidden();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->withSession(['organization_id' => $stranger->personalOrganization()->id])
            ->get('/records?'.http_build_query(['tab' => 'panorama', 'class' => $this->schoolClass->ulid]))
            ->assertNotFound();
    }

    #[Test]
    public function the_panorama_costs_the_same_queries_for_five_students_or_thirty(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->panorama();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        for ($number = 1; $number <= 5; $number++) {
            $student = $this->enroll("Aluno {$number}", ['class_number' => $number]);
            $this->record($student, EvidenceKind::Lateness, '2026-10-06');
            $this->record($student, EvidenceKind::Homework, '2026-10-06', HomeworkStatus::NotDone);
        }
        // The first request of a test also fills per-process caches (plan,
        // collection state) — measured from the second one on.
        $count();
        $few = $count();

        for ($number = 6; $number <= 30; $number++) {
            $student = $this->enroll("Aluno {$number}", ['class_number' => $number]);
            $this->record($student, EvidenceKind::Lateness, '2026-10-06');
            $this->record($student, EvidenceKind::Homework, '2026-10-06', HomeworkStatus::NotDone);
        }

        // The names are decrypted from the identities eager-loaded with the roll.
        $this->assertSame($few, $count());
    }
}
