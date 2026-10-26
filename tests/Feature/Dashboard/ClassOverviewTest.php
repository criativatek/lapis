<?php

namespace Tests\Feature\Dashboard;

use App\Models\AttendanceStatus;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\InstrumentStatus;
use App\Models\Intervention;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\LessonAttendance;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentStatus;
use App\Models\SelfAssessmentTemplate;
use App\Services\Dashboard\ClassOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Dashboard\Concerns\BuildsDashboardFixtures;
use Tests\TestCase;

class ClassOverviewTest extends TestCase
{
    use BuildsDashboardFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDashboardFixtures();
    }

    #[Test]
    public function each_class_card_is_built_from_grouped_facts_of_the_current_period_and_week(): void
    {
        $ana = $this->enroll('Ana Costa');
        $rui = $this->enroll('Rui Pires');
        $this->enroll('Saiu', ['status' => 'left', 'left_on' => '2026-10-01']);
        $this->slotFor($this->schoolClass, 3, '14:00', '15:00');
        $lesson = $this->lessonFor($this->schoolClass, '2026-10-26 08:30:00', 90, ['attendance_recorded_at' => '2026-10-26 10:05:00', 'attendance_recorded_by' => $this->teacher->id]);
        $this->instrumentFor($this->schoolClass, 'Concluído', '2026-10-01', InstrumentStatus::Completed);
        $this->instrumentFor($this->schoolClass, 'Em correção', '2026-10-20', InstrumentStatus::InCorrection);
        $this->instrumentFor($this->schoolClass, 'Aplicado', '2026-10-22', InstrumentStatus::Prepared);
        $this->instrumentFor($this->schoolClass, 'Marcado', '2026-11-05', InstrumentStatus::Prepared);
        $this->instrumentFor($this->schoolClass, 'Anulado', '2026-10-10', InstrumentStatus::Cancelled);
        $this->instrumentFor($this->schoolClass, 'Rascunho', '2026-11-12', InstrumentStatus::Draft);
        $this->instrumentFor($this->schoolClass, 'Do 2.º período', '2027-02-10', InstrumentStatus::Prepared, $this->secondPeriod);

        $this->inTenant($this->organization, function () use ($ana, $rui, $lesson): void {
            foreach ([$ana, $rui] as $enrollment) {
                LessonAttendance::create(['lesson_id' => $lesson->id, 'enrollment_id' => $enrollment->id, 'student_id' => $enrollment->student_id, 'status' => AttendanceStatus::Absent, 'updated_by' => $this->teacher->id]);
            }

            $base = ['class_id' => $this->schoolClass->id, 'enrollment_id' => $ana->id, 'academic_period_id' => $this->firstPeriod->id, 'target_type' => InterventionTargetType::Student, 'domain_relation' => InterventionDomainRelation::None, 'intervention_type' => 'other', 'purpose' => 'recovery', 'description' => 'Apoio.', 'started_on' => '2026-10-01'];
            Intervention::create($base + ['title' => 'Em curso', 'status' => InterventionStatus::InProgress]);
            Intervention::create($base + ['title' => 'Concluída', 'status' => InterventionStatus::Concluded, 'concluded_on' => '2026-10-20']);

            foreach (['2026-10-02', '2026-10-09', '2026-09-01'] as $date) {   // a terceira é anterior ao período
                EvidenceRecord::create(['class_id' => $this->schoolClass->id, 'enrollment_id' => $ana->id, 'occurred_at' => $date.' 10:00:00', 'kind' => EvidenceKind::Participation, 'participation_level' => 'positive', 'description' => 'Participou.', 'created_by' => $this->teacher->id]);
            }

            $template = SelfAssessmentTemplate::create(['class_id' => $this->schoolClass->id, 'name' => 'Autoavaliação', 'is_active' => true]);
            SelfAssessment::create(['enrollment_id' => $ana->id, 'academic_period_id' => $this->firstPeriod->id, 'self_assessment_template_id' => $template->id, 'status' => SelfAssessmentStatus::Submitted, 'filled_by' => SelfAssessmentFilledBy::Student, 'submitted_at' => now()]);
            SelfAssessment::create(['enrollment_id' => $rui->id, 'academic_period_id' => $this->firstPeriod->id, 'self_assessment_template_id' => $template->id, 'status' => SelfAssessmentStatus::Draft, 'filled_by' => SelfAssessmentFilledBy::Student]);
        });

        $cards = $this->within(fn () => app(ClassOverview::class)->for($this->context()));

        $this->assertCount(1, $cards);
        $card = $cards[0];
        $this->assertSame('7.º A', $card['label']);
        $this->assertSame(2, $card['students']);
        $this->assertSame(['date' => '2026-10-28', 'start_time' => '14:00', 'is_today' => false], array_intersect_key($card['next_lesson'], array_flip(['date', 'start_time', 'is_today'])));
        $this->assertSame(2, $card['absences_this_week']);
        $this->assertSame(1, $card['open_interventions']);
        $this->assertSame(['period_label' => '1.º Período', 'total' => 4, 'corrected' => 1, 'in_correction' => 1, 'applied' => 1, 'to_apply' => 1, 'drafts' => 1], $card['instruments']);
        $this->assertSame(2, $card['records_in_period']);
        $this->assertSame('2026-10-09', substr((string) $card['last_record_at'], 0, 10));
        $this->assertSame(['submitted' => 1, 'total' => 2], $card['self_assessments']);
        $this->assertNotNull($card['class']['tone']);
    }

    #[Test]
    public function a_base_card_has_no_timetable_facts_but_keeps_the_rest(): void
    {
        $this->enroll('Ana Costa');
        $this->slotFor($this->schoolClass, 3, '14:00', '15:00');
        $this->instrumentFor($this->schoolClass, 'Em correção', '2026-10-20', InstrumentStatus::InCorrection);
        $this->downgradeToBase();

        $card = $this->within(fn () => app(ClassOverview::class)->for($this->context()))[0];

        $this->assertNull($card['next_lesson']);
        $this->assertNull($card['absences_this_week']);
        $this->assertSame(1, $card['students']);
        $this->assertSame(1, $card['instruments']['in_correction']);
    }
}
