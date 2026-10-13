<?php

namespace Tests\Feature\Evidence;

use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Evidence\Concerns\BuildsFollowupFixtures;
use Tests\TestCase;

/**
 * «Registos» — the consultation (spec 2026-10-12): filters and a compact
 * summary on entry, never the whole list; the three direct accesses agree
 * with their lists and their details; and nothing reaches beyond the classes
 * the person teaches.
 */
class RecordConsultationTest extends TestCase
{
    use BuildsFollowupFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFollowupFixtures();
    }

    /**
     * @param  array<string, string>  $query
     */
    private function consult(array $query = [], ?User $as = null): TestResponse
    {
        $request = $as === null ? $this->asTeacher() : $this->actingAs($as)->withSession(['organization_id' => $this->organization->id]);

        return $request->get('/records'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    #[Test]
    public function the_entry_shows_filters_and_a_summary_but_never_the_list_of_records(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-01');
        $this->record($ana, EvidenceKind::Note, '2026-10-02');

        $this->consult()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('records/Index')
            ->where('results', null)
            ->where('followup', null)
            ->where('openCase', null)
            ->where('filters.period', $this->firstPeriod->ulid)
            ->where('filters.searched', false)
            ->where('context.period_is_current', true)
            ->where('context.needs_window', false)
            ->has('tiles', 4)
            ->where('tiles.3.category', 'absences')
            ->where('tiles.3.pending', null)
            ->where('tiles.1.category', 'lateness')
            ->where('tiles.1.students', 1)
            ->where('tiles.1.occurrences', 1)
            ->where('tiles.1.pending', 0));
    }

    #[Test]
    public function a_search_returns_the_matching_records_paginated_on_the_server(): void
    {
        $ana = $this->enroll('Ana Costa');

        for ($day = 1; $day <= 27; $day++) {
            $this->record($ana, EvidenceKind::Lateness, sprintf('2026-10-%02d', $day));
        }
        $this->record($ana, EvidenceKind::Note, '2026-10-02');

        $this->consult(['searched' => '1', 'kind' => 'lateness'])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.total', 27)
            ->where('results.current_page', 1)
            ->where('results.last_page', 2)
            ->has('results.data', 25)
            ->where('results.data.0.date', '2026-10-27')
            ->where('results.data.0.student.name', 'Ana Costa'));

        $this->consult(['searched' => '1', 'kind' => 'lateness', 'page' => '2'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('results.data', 2)
            ->where('results.data.1.date', '2026-10-01'));
    }

    #[Test]
    public function a_search_without_matches_is_an_empty_result_not_the_initial_state(): void
    {
        $this->enroll('Ana Costa');

        $this->consult(['searched' => '1', 'kind' => 'incident'])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.total', 0)
            ->has('results.data', 0));
    }

    #[Test]
    public function without_a_current_period_nothing_is_chosen_silently(): void
    {
        $this->travelTo(Carbon::parse('2026-12-24 10:00:00', 'Europe/Lisbon'));
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-01');

        $this->consult()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.period', null)
            ->where('context.needs_window', true)
            ->where('tiles', [])
            ->where('results', null));

        $this->consult(['period' => $this->firstPeriod->ulid])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('context.needs_window', false)
            ->where('context.period_is_current', false)
            ->where('tiles.1.occurrences', 1));
    }

    #[Test]
    public function a_year_without_periods_asks_for_them_and_still_answers_by_dates(): void
    {
        $class = $this->inTenant($this->organization, function (): SchoolClass {
            $year = AcademicYear::factory()->recycle($this->organization)->create(['label' => '2027/2028', 'starts_on' => '2027-09-01', 'ends_on' => '2028-08-31']);
            $class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $year->id]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class->load('academicYear');
        });
        $ana = $this->enroll('Ana Costa', ['enrolled_on' => '2027-09-01'], $class);
        $this->record($ana, EvidenceKind::Lateness, '2027-10-01', class: $class);

        $this->consult(['year' => $class->academicYear->ulid])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('context.needs_window', true)
            ->where('context.periods_notice', 'Para receber alertas de acumulação, configure os períodos de avaliação e confirme as respetivas datas.'));

        $this->consult(['year' => $class->academicYear->ulid, 'mode' => 'range', 'from' => '2027-10-01', 'to' => '2027-10-31'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('context.needs_window', false)
                ->where('tiles.1.occurrences', 1)
                ->where('tiles.1.pending', null));
    }

    #[Test]
    public function an_impossible_date_range_is_reported_not_searched(): void
    {
        $this->consult(['mode' => 'range', 'from' => '2026-10-31', 'to' => '2026-10-01', 'searched' => '1'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('context.needs_window', true)
                ->where('context.window_problem', 'A data inicial não pode ser posterior à data final.')
                ->where('results', null));
    }

    #[Test]
    public function semesters_are_read_as_configured(): void
    {
        $this->inTenant($this->organization, fn () => AcademicPeriod::query()->delete());
        $first = $this->makePeriod(1, '1.º Semestre', '2026-09-14', '2027-01-29', kind: AcademicPeriodKind::Semester);
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::MissingMaterial, '2026-10-01');
        $this->record($ana, EvidenceKind::MissingMaterial, '2027-01-29');
        $this->record($ana, EvidenceKind::MissingMaterial, '2027-02-01');

        $this->consult()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.period', $first->ulid)
            ->where('tiles.2.category', 'missing_material')
            ->where('tiles.2.occurrences', 2));
    }

    #[Test]
    public function the_summary_the_list_and_the_detail_count_the_same_occurrences(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 1]);
        $rui = $this->enroll('Rui Sousa', ['class_number' => 2]);
        $eva = $this->enroll('Eva Lima', ['class_number' => 3]);

        foreach (['2026-10-01', '2026-10-01', '2026-10-08', '2026-10-09'] as $date) {
            $this->record($rui, EvidenceKind::Lateness, $date);
        }
        foreach (['2026-10-02', '2026-10-03'] as $date) {
            $this->record($ana, EvidenceKind::Lateness, $date);
            $this->record($eva, EvidenceKind::Lateness, $date);
        }
        $this->record($ana, EvidenceKind::MissingMaterial, '2026-10-04');

        $this->consult(['view' => 'lateness'])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tiles.1.students', 3)
            ->where('tiles.1.occurrences', 8)
            ->where('tiles.1.pending', 1)
            ->where('followup.category', 'lateness')
            ->where('followup.rows.total', 3)
            // Most first; equal counts by number in class.
            ->where('followup.rows.data.0.name', 'Rui Sousa')
            ->where('followup.rows.data.0.occurrences', 4)
            ->where('followup.rows.data.0.status', 'pending')
            ->where('followup.rows.data.0.origin', 'existing')
            ->where('followup.rows.data.1.name', 'Ana Costa')
            ->where('followup.rows.data.1.status', 'none')
            ->where('followup.rows.data.2.name', 'Eva Lima'));

        $this->consult(['view' => 'lateness', 'case_class' => $this->schoolClass->ulid, 'case_student' => $rui->ulid, 'case_category' => 'lateness'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('openCase.count', 4)
                ->has('openCase.occurrences', 4)
                ->where('openCase.occurrences.0.date', '2026-10-01')
                ->where('openCase.occurrences.1.date', '2026-10-01')
                ->where('openCase.status', 'pending')
                ->where('openCase.message.text', 'O aluno n.º 2, Rui Sousa, acumulou quatro faltas por atraso na disciplina de '.$this->schoolClass->subject->name.', no 1.º Período, nos dias 01/10 (duas ocorrências), 08/10 e 09/10. Solicita-se acompanhamento no sentido de promover o cumprimento do horário de início das aulas.')
                ->where('openCase.can_confirm', true));
    }

    #[Test]
    public function a_correction_or_a_deletion_shows_at_once_in_every_count(): void
    {
        $ana = $this->enroll('Ana Costa');
        $records = collect(['2026-10-01', '2026-10-02', '2026-10-05'])->map(fn (string $date) => $this->record($ana, EvidenceKind::Homework, $date));

        $this->consult(['view' => 'homework_not_done'])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tiles.0.occurrences', 3)->where('tiles.0.pending', 1));

        $this->putRecord($records[0], ['homework_status' => 'partially_done'])->assertRedirect();
        $this->asTeacher()->delete('/records/'.$records[1]->ulid)->assertRedirect();

        $this->consult(['view' => 'homework_not_done'])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tiles.0.occurrences', 1)
            ->where('tiles.0.pending', 0)
            ->where('followup.rows.data.0.occurrences', 1)
            ->where('followup.rows.data.0.status', 'none'));
    }

    #[Test]
    public function the_alerts_stay_listed_across_periods_until_handled(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-01');
        $this->record($ana, EvidenceKind::Lateness, '2026-10-02');
        $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => '2026-10-05']);

        $this->consult(['period' => $this->secondPeriod->ulid])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('alerts', 1)
            ->where('alerts.0.name', 'Ana Costa')
            ->where('alerts.0.category', 'lateness')
            ->where('alerts.0.occurrences', 3)
            ->where('alerts.0.period_label', '1.º Período'));
    }

    #[Test]
    public function a_class_the_person_does_not_teach_is_not_found_and_never_counted(): void
    {
        $colleague = $this->colleagueTeaching();
        $foreign = $this->inTenant($this->organization, function () use ($colleague): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $this->schoolClass->academic_year_id, 'label' => '9.º C']);
            $class->teachers()->attach($colleague, ['role' => 'owner']);

            return $class;
        });
        $stranger = $this->enroll('Aluno de outro professor', [], $foreign);
        foreach (['2026-10-01', '2026-10-02', '2026-10-05'] as $date) {
            $this->record($stranger, EvidenceKind::Lateness, $date, class: $foreign);
        }

        $this->consult(['class' => $foreign->ulid])->assertNotFound();
        $this->consult()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tiles.1.occurrences', 0)
            ->has('options.classes', 1));
        $this->consult(['view' => 'lateness', 'case_class' => $foreign->ulid, 'case_student' => $stranger->ulid, 'case_category' => 'lateness'])->assertNotFound();

        // The owner of the organization (the teacher above) never read it; the
        // colleague who teaches it does.
        $this->consult([], $colleague)->assertInertia(fn (AssertableInertia $page) => $page->where('tiles.1.occurrences', 3));
    }

    #[Test]
    public function another_organizations_period_class_or_student_is_not_found(): void
    {
        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();
        [$foreignClass, $foreignPeriodUlid] = $this->inTenant($strangerOrganization, function () use ($strangerOrganization, $stranger): array {
            $class = SchoolClass::factory()->recycle($strangerOrganization)->create();
            $class->teachers()->attach($stranger, ['role' => 'owner']);
            $period = AcademicPeriod::create([
                'academic_year_id' => $class->academic_year_id, 'label' => '1.º Período', 'kind' => 'term',
                'sequence' => 1, 'starts_on' => '2026-09-14', 'ends_on' => '2026-12-16', 'status' => 'draft',
            ]);

            return [$class, $period->ulid];
        });

        $this->consult(['class' => $foreignClass->ulid])->assertNotFound();
        $this->consult(['period' => $foreignPeriodUlid])->assertNotFound();
        $this->consult(['student' => '01JFAKEULID0000000000000000'])->assertNotFound();
    }

    #[Test]
    public function the_student_filter_belongs_to_a_class_and_lists_its_whole_roll(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 4]);
        $left = $this->enroll('Saiu em outubro', ['status' => 'left', 'left_on' => '2026-10-05', 'class_number' => 9]);
        $this->record($left, EvidenceKind::Lateness, '2026-10-01');

        $this->consult(['class' => $this->schoolClass->ulid])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('options.students', 2)
            ->where('options.students.0.number', 4));

        $this->consult(['class' => $this->schoolClass->ulid, 'student' => $left->ulid, 'view' => 'lateness'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tiles.1.occurrences', 1)
                ->where('followup.rows.data.0.name', 'Saiu em outubro'));

        $this->assertNotSame($ana->ulid, $left->ulid);
    }

    #[Test]
    public function the_class_logbook_lists_only_the_latest_records_and_opens_one_from_the_consultation(): void
    {
        $ana = $this->enroll('Ana Costa');
        $old = $this->record($ana, EvidenceKind::Lateness, '2026-09-15');

        for ($day = 1; $day <= 12; $day++) {
            $this->record($ana, EvidenceKind::Note, sprintf('2026-10-%02d', $day));
        }

        $back = '/records?view=lateness&page=2';

        $this->asTeacher()->get('/classes/'.$this->schoolClass->ulid.'/records?registo='.$old->ulid.'&voltar='.urlencode($back))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('records/Show')
                ->has('records', 10)
                ->where('editingRecord.ulid', $old->ulid)
                ->where('backUrl', $back)
                ->where('periodsNotice', null));

        // Only a way back into the consultation is honoured.
        $this->asTeacher()->get('/classes/'.$this->schoolClass->ulid.'/records?voltar='.urlencode('https://example.com/records'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('backUrl', null));

        // A record of another class is never opened through this one — it is
        // as absent as a deleted one, and nothing of it reaches the page.
        $other = $this->secondClass();
        $elsewhere = $this->record($this->enroll('Rui Sousa', [], $other), EvidenceKind::Note, '2026-10-01', class: $other);
        $this->asTeacher()->get('/classes/'.$this->schoolClass->ulid.'/records?registo='.$elsewhere->ulid)
            ->assertOk()
            ->assertDontSee('Rui Sousa')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('editingRecord', null)->where('editingRecordMissing', true));
    }

    #[Test]
    public function a_record_deleted_after_being_opened_says_so_instead_of_a_404(): void
    {
        $ana = $this->enroll('Ana Costa');
        $record = $this->record($ana, EvidenceKind::Lateness, '2026-10-01');
        $url = '/classes/'.$this->schoolClass->ulid.'/records?registo='.$record->ulid.'&voltar='.urlencode('/records?page=2');

        $this->asTeacher()->from($url)->delete('/records/'.$record->ulid)->assertRedirect($url);

        $this->asTeacher()->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('editingRecord', null)
            ->where('editingRecordMissing', true)
            ->where('backUrl', '/records?page=2'));
    }

    #[Test]
    public function a_malformed_query_string_never_breaks_the_page(): void
    {
        $this->consult(['view' => ['lateness'], 'case_category' => ['x'], 'kind' => ['y'], 'mode' => ['range']])->assertOk();
    }

    #[Test]
    public function one_student_in_two_classes_is_one_student_two_cases_and_two_separate_alerts(): void
    {
        $ana = $this->enroll('Ana Costa', ['class_number' => 3]);
        $support = $this->secondClass('7.º A (apoio)', $this->inTenant($this->organization, fn () => $this->schoolClass->subject));
        // A support class reuses the same student (StudentEnrollmentService::enrollExisting);
        // a homonym in another class is ANOTHER student and is never merged by name.
        $anaInSupport = $this->inTenant($this->organization, fn (): Enrollment => Enrollment::create([
            'class_id' => $support->id, 'student_id' => $ana->student_id, 'class_number' => 1, 'enrolled_on' => '2026-09-01', 'status' => 'active', 'is_late_entry' => false,
        ]));
        $homonym = $this->enroll('Ana Costa', ['class_number' => 2], $support);

        foreach (['2026-10-01', '2026-10-02'] as $date) {
            $this->record($ana, EvidenceKind::Lateness, $date);
            $this->record($anaInSupport, EvidenceKind::Lateness, $date, class: $support);
        }
        $this->record($homonym, EvidenceKind::Lateness, '2026-10-02', class: $support);

        $this->postRecord(['enrollment_ids' => [$ana->id], 'occurred_at' => '2026-10-05'])->assertRedirect();
        $this->postRecord(['enrollment_ids' => [$anaInSupport->id], 'occurred_at' => '2026-10-05'], $support)->assertRedirect();

        $this->assertSame(2, $this->alertCount());

        $this->consult(['view' => 'lateness'])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tiles.1.students', 2)
            ->where('tiles.1.cases', 3)
            ->where('tiles.1.occurrences', 7)
            ->where('tiles.1.pending', 2)
            ->where('followup.rows.total', 3)
            ->where('followup.rows.data.0.student_cases', 2)
            ->where('followup.rows.data.1.student_cases', 2)
            ->where('followup.rows.data.2.student_cases', 1)
            ->where('followup.rows.data.2.occurrences', 1)
            ->has('alerts', 2));
    }

    #[Test]
    public function the_class_logbook_tells_the_form_which_day_today_is_in_the_application_zone(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 00:30:00', 'Europe/Lisbon'));

        $this->asTeacher()->get('/classes/'.$this->schoolClass->ulid.'/records')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('appTimezone', 'Europe/Lisbon'));

        // The day the form proposes at 00:30 in Lisbon (2026-09-14, the first
        // day of the period) is inside the first period.
        $ana = $this->enroll('Ana Costa');
        $this->record($ana, EvidenceKind::Lateness, '2026-09-14');
        $this->consult()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.period', $this->firstPeriod->ulid)
            ->where('tiles.1.occurrences', 1));
    }

    #[Test]
    public function the_dashboard_shows_the_alerts_still_to_handle(): void
    {
        $ana = $this->enroll('Ana Costa');

        $this->asTeacher()->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('recordAlerts', null));

        $this->record($ana, EvidenceKind::Homework, '2026-10-01', HomeworkStatus::NotDone);
        $this->record($ana, EvidenceKind::Homework, '2026-10-02', HomeworkStatus::NotDone);
        $this->saveHomework('2026-10-05', [$ana->id => 'not_done']);

        $this->asTeacher()->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('recordAlerts.pending', 1));

        $this->inTenant($this->organization, fn () => EvidenceRecord::query()->whereDate('occurred_at', '2026-10-05')->first()?->delete());

        $this->asTeacher()->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('recordAlerts', null));
    }
}
