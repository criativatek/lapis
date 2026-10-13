<?php

namespace Tests\Feature\Evidence;

use App\Models\AcademicYear;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\RecordFollowupCase;
use App\Models\RecordFollowupCategory;
use App\Models\SchoolClass;
use App\Services\Evidence\Followup\RecordFollowupAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Evidence\Concerns\BuildsFollowupFixtures;
use Tests\TestCase;

/**
 * The alert at the third occurrence — decided 2026-10-12: one alert per
 * student × class × category × period when an operation takes the count from
 * below 3 to 3 or more; no cycles at 6 or 9; nothing outside configured
 * periods; never a second alert for the same key, however the save repeats.
 */
class RecordFollowupAlertTest extends TestCase
{
    use BuildsFollowupFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFollowupFixtures();
    }

    #[Test]
    public function the_first_two_lateness_records_raise_nothing_and_the_third_raises_one_alert(): void
    {
        $ana = $this->enroll('Ana Costa');

        $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => '2026-10-01'])->assertRedirect();
        $this->assertSame('success', $this->toast()['type']);
        $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => '2026-10-05'])->assertRedirect();
        $this->assertSame(0, $this->alertCount());

        $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => '2026-10-12'])->assertRedirect();

        $toast = $this->toast();
        $this->assertSame('warning', $toast['type']);
        $this->assertStringContainsString('Ana Costa acumulou 3 atrasos no 1.º Período', $toast['message']);
        $this->assertStringContainsString('O alerta ficou em Registos, por tratar.', $toast['message']);

        $case = $this->caseFor($ana, 'lateness');
        $this->assertNotNull($case);
        $this->assertNotNull($case->alerted_at);
        $this->assertSame(3, $case->alerted_count);
    }

    #[Test]
    public function the_fourth_and_the_sixth_never_alert_again(): void
    {
        $ana = $this->enroll('Ana Costa');

        foreach (['2026-10-01', '2026-10-02', '2026-10-05'] as $date) {
            $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => $date]);
        }

        foreach (['2026-10-06', '2026-10-07', '2026-10-08'] as $date) {
            $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => $date])->assertRedirect();
            $this->assertSame('success', $this->toast()['type']);
            $this->assertStringNotContainsString('acumulou', $this->toast()['message']);
        }

        $this->assertSame(1, $this->alertCount());
    }

    #[Test]
    public function one_operation_that_goes_from_two_to_four_raises_a_single_alert_with_the_real_count(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::MissingMaterial, '2026-10-01');
        $this->record($ana, EvidenceKind::MissingMaterial, '2026-10-02');

        // The multi-student grid sends the same student twice: two rows, one
        // operation.
        $this->postRecord(['kind' => 'missing_material', 'enrollment_ids' => [$ana->id, $ana->id], 'occurred_at' => '2026-10-05'])->assertRedirect();

        $this->assertSame(1, $this->alertCount());
        $this->assertSame(4, $this->caseFor($ana, 'missing_material')?->alerted_count);
        $this->assertStringContainsString('acumulou 4 faltas de material', $this->toast()['message']);
    }

    #[Test]
    public function the_categories_and_the_students_are_alerted_separately(): void
    {
        $ana = $this->enroll('Ana Costa');
        $rui = $this->enroll('Rui Sousa');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-01');
        $this->record($ana, EvidenceKind::MissingMaterial, '2026-10-02');
        $this->record($rui, EvidenceKind::Lateness, '2026-10-01');
        $this->record($rui, EvidenceKind::Lateness, '2026-10-02');

        // Ana: one lateness, one missing material, one homework — never three.
        $this->saveHomework('2026-10-05', [$ana->id => 'not_done', $rui->id => 'done'])->assertRedirect();
        $this->assertSame(0, $this->alertCount());

        // Two students in one save, one of them crossing.
        $this->postRecord(['enrollment_ids' => [$ana->id, $rui->id], 'occurred_at' => '2026-10-06'])->assertRedirect();

        $this->assertSame(1, $this->alertCount());
        $this->assertNotNull($this->caseFor($rui, 'lateness'));
        $this->assertNull($this->caseFor($ana, 'lateness'));
    }

    #[Test]
    public function the_homework_grid_alerts_on_the_third_not_done_and_partial_never_counts(): void
    {
        $ana = $this->enroll('Ana Costa');

        $this->saveHomework('2026-10-01', [$ana->id => 'not_done']);
        $this->saveHomework('2026-10-02', [$ana->id => 'partially_done']);
        $this->saveHomework('2026-10-05', [$ana->id => 'not_done']);
        $this->assertSame(0, $this->alertCount());

        $this->saveHomework('2026-10-06', [$ana->id => 'not_done'])->assertRedirect();

        $this->assertSame('warning', $this->toast()['type']);
        $this->assertStringContainsString('Trabalho de casa guardado.', $this->toast()['message']);
        $this->assertStringContainsString('acumulou 3 TPC não realizados', $this->toast()['message']);
        $this->assertNotNull($this->caseFor($ana, 'homework_not_done'));
    }

    #[Test]
    public function re_saving_the_third_record_or_the_same_grid_never_raises_a_second_alert(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-01');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-02');
        $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => '2026-10-05']);
        $third = $this->inTenant($this->organization, fn () => EvidenceRecord::query()->latest('id')->firstOrFail());

        $this->putRecord($third, ['description' => 'Chegou 10 minutos depois.'])->assertRedirect();
        $this->assertSame('success', $this->toast()['type']);

        foreach (['2026-10-06', '2026-10-07', '2026-10-08'] as $date) {
            $this->saveHomework($date, [$ana->id => 'not_done']);
        }
        $this->saveHomework('2026-10-08', [$ana->id => 'not_done'])->assertRedirect();
        $this->assertSame('success', $this->toast()['type']);

        $this->assertSame(2, $this->alertCount());
    }

    #[Test]
    public function deleting_the_third_and_writing_it_again_does_not_alert_twice(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-01');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-02');
        $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => '2026-10-05']);
        $third = $this->inTenant($this->organization, fn () => EvidenceRecord::query()->latest('id')->firstOrFail());

        $this->asTeacher()->delete('/records/'.$third->ulid)->assertRedirect();
        $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => '2026-10-05'])->assertRedirect();

        $this->assertSame(1, $this->inTenant($this->organization, fn () => RecordFollowupCase::query()->count()));
        $this->assertSame('success', $this->toast()['type']);
    }

    #[Test]
    public function a_concurrent_save_that_already_claimed_the_key_leaves_one_alert(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-01');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-02');

        // Two writers each took their «before» at 2; the other one committed
        // first and claimed the key.
        $alerts = app(RecordFollowupAlerts::class);
        [$first, $second] = $this->inTenant($this->organization, fn (): array => [
            $alerts->before($this->schoolClass, [['enrollment_id' => $ana->id, 'category' => RecordFollowupCategory::Lateness, 'date' => Carbon::parse('2026-10-05')]]),
            $alerts->before($this->schoolClass, [['enrollment_id' => $ana->id, 'category' => RecordFollowupCategory::Lateness, 'date' => Carbon::parse('2026-10-06')]]),
        ]);
        $this->record($ana, EvidenceKind::Lateness, '2026-10-05');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-06');

        $outcomes = $this->inTenant($this->organization, fn (): array => [$alerts->after($first), $alerts->after($second)]);

        $this->assertCount(1, $outcomes[0]->alerted);
        $this->assertSame([], $outcomes[1]->alerted);
        $this->assertSame(1, $this->alertCount());
        $this->assertSame(4, $this->caseFor($ana, 'lateness')?->alerted_count);
    }

    #[Test]
    public function the_unique_key_refuses_a_second_row_for_the_same_case(): void
    {
        $ana = $this->enroll('Ana Costa');
        $row = fn (): array => [
            'ulid' => (new RecordFollowupCase)->newUniqueId(),
            'organization_id' => $this->organization->id,
            'class_id' => $this->schoolClass->id,
            'enrollment_id' => $ana->id,
            'academic_period_id' => $this->firstPeriod->id,
            'category' => 'lateness',
            'alerted_at' => now(),
            'alerted_count' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->assertSame(1, DB::table('record_followup_cases')->insertOrIgnore($row()));
        $this->assertSame(0, DB::table('record_followup_cases')->insertOrIgnore($row()));
    }

    #[Test]
    public function correcting_a_record_into_lateness_or_into_not_done_can_cross_the_threshold(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-01');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-02');
        $note = $this->inTenant($this->organization, fn () => EvidenceRecord::create([
            'class_id' => $this->schoolClass->id, 'enrollment_id' => $ana->id, 'occurred_at' => '2026-10-05',
            'kind' => 'note', 'description' => 'Entrou tarde.', 'created_by' => $this->teacher->id,
        ]));

        $this->putRecord($note, ['kind' => 'lateness'])->assertRedirect();
        $this->assertSame('warning', $this->toast()['type']);
        $this->assertNotNull($this->caseFor($ana, 'lateness'));

        $this->record($ana, EvidenceKind::Homework, '2026-10-01');
        $this->record($ana, EvidenceKind::Homework, '2026-10-02');
        $partial = $this->record($ana, EvidenceKind::Homework, '2026-10-05', HomeworkStatus::PartiallyDone);
        $this->assertNull($this->caseFor($ana, 'homework_not_done'));

        $this->putRecord($partial, ['homework_status' => 'not_done'])->assertRedirect();
        $this->assertNotNull($this->caseFor($ana, 'homework_not_done'));
    }

    #[Test]
    public function moving_the_date_into_another_period_counts_there(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2027-01-11');
        $this->record($ana, EvidenceKind::Lateness, '2027-01-12');
        $moved = $this->record($ana, EvidenceKind::Lateness, '2026-10-05');

        $this->putRecord($moved, ['occurred_at' => '2027-01-13', 'description' => 'Chegou depois do toque.'])->assertRedirect();

        $this->assertNull($this->caseFor($ana, 'lateness'));
        $this->assertNotNull($this->caseFor($ana, 'lateness', $this->secondPeriod));
    }

    #[Test]
    public function three_occurrences_spread_over_two_periods_never_alert(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-12-10');
        $this->record($ana, EvidenceKind::Lateness, '2026-12-11');

        $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => '2027-01-05'])->assertRedirect();

        $this->assertSame(0, $this->alertCount());
    }

    #[Test]
    public function an_occurrence_outside_every_period_never_alerts_and_says_how_to_fix_it(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-12-21');
        $this->record($ana, EvidenceKind::Lateness, '2026-12-22');

        $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => '2026-12-23'])->assertRedirect();

        $this->assertSame(0, $this->alertCount());
        $this->assertSame('info', $this->toast()['type']);
        $this->assertStringContainsString('Para receber alertas de acumulação, configure os períodos de avaliação e confirme as respetivas datas.', $this->toast()['message']);
    }

    #[Test]
    public function a_year_without_periods_never_alerts_and_never_falls_back_to_the_whole_year(): void
    {
        $class = $this->inTenant($this->organization, function (): SchoolClass {
            $year = AcademicYear::factory()->recycle($this->organization)->create(['label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
            $class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $year->id]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });
        $ana = $this->enroll('Ana Costa', ['enrolled_on' => '2025-09-01'], $class);

        foreach (['2026-05-04', '2026-05-05', '2026-05-06'] as $date) {
            $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => $date], $class)->assertRedirect();
        }

        $this->assertSame(0, $this->alertCount());
        $this->assertStringContainsString('configure os períodos de avaliação', $this->toast()['message']);
    }

    #[Test]
    public function cases_already_at_three_or_more_before_this_version_raise_nothing_by_themselves(): void
    {
        $ana = $this->enroll('Ana Costa');
        $rui = $this->enroll('Rui Sousa');

        foreach (['2026-09-21', '2026-09-28', '2026-10-05'] as $date) {
            $this->record($ana, EvidenceKind::Lateness, $date);
            $this->record($rui, EvidenceKind::MissingMaterial, $date);
        }

        // Opening the screens never writes.
        $this->asTeacher()->get('/records')->assertOk();
        $this->asTeacher()->get('/classes/'.$this->schoolClass->ulid.'/records')->assertOk();
        $this->asTeacher()->get('/dashboard')->assertOk();
        $this->assertSame(0, $this->inTenant($this->organization, fn () => RecordFollowupCase::query()->count()));

        // A fourth one is 3→4: no crossing, no alert.
        $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => '2026-10-12'])->assertRedirect();
        $this->assertSame(0, $this->alertCount());
    }

    #[Test]
    public function a_whole_class_record_never_counts_for_anyone(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-01');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-02');

        $this->postRecord(['enrollment_ids' => [], 'occurred_at' => '2026-10-05'])->assertRedirect();

        $this->assertSame(0, $this->alertCount());
    }
}
