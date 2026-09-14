<?php

namespace Tests\Feature\Activity;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\InterventionType;
use App\Models\LessonStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * Coverage added by the 0.145.1 «Minha atividade» slice: the events an
 * everyday teaching session actually produces (turma, inscrição, aula,
 * elemento de avaliação, notas, medida, registo) really land in the trail —
 * and never for a request that failed, never with a student's name in the
 * text, and never in front of anyone but the causer's own organization.
 */
class AuditTrailCoverageTest extends TestCase
{
    use BuildsLessonFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    protected function inTenantOrg(callable $callback): mixed
    {
        return $this->inTenant($this->organization, $callback);
    }

    // ------------------------------------------------------------ class

    #[Test]
    public function creating_a_class_records_an_audit_event(): void
    {
        $year = $this->inTenantOrg(fn () => AcademicYear::factory()->recycle($this->organization)
            ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']));
        $subject = $this->inTenantOrg(fn () => Subject::factory()->recycle($this->organization)->create());

        $this->asTeacher()->post('/classes', [
            'label' => '8.º B',
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
        ])->assertRedirect();

        $this->inTenantOrg(function () use ($year, $subject): void {
            $class = SchoolClass::where('academic_year_id', $year->id)->where('subject_id', $subject->id)->firstOrFail();
            $event = AuditEvent::where('event', 'class.created')->where('subject_id', $class->id)->sole();
            $this->assertSame($this->teacher->id, $event->causer_id);
        });
    }

    #[Test]
    public function a_failing_class_creation_records_no_audit_event(): void
    {
        // No 'label' at all — ClassRequest rejects it before the controller
        // ever runs.
        $this->asTeacher()->post('/classes', [])->assertSessionHasErrors('label');

        $this->inTenantOrg(fn () => $this->assertSame(0, AuditEvent::where('event', 'class.created')->count()));
    }

    // ------------------------------------------------------------ enrollment

    #[Test]
    public function enrolling_a_student_records_an_audit_event_that_never_names_them(): void
    {
        $this->asTeacher()->post("/classes/{$this->schoolClass->ulid}/students", [
            'name' => 'Segredo Confidencial',
        ])->assertRedirect();

        $this->inTenantOrg(function (): void {
            $event = AuditEvent::where('event', 'enrollment.created')->sole();
            $this->assertSame($this->teacher->id, $event->causer_id);
            $this->assertTrue($event->properties['student_created']);

            $this->assertStringNotContainsString('Segredo Confidencial', (string) $event->summary);
            $this->assertStringNotContainsString(
                'Segredo Confidencial',
                json_encode($event->properties),
            );
        });
    }

    // ------------------------------------------------------------ lesson summary

    #[Test]
    public function a_second_save_of_the_same_summary_records_a_summary_saved_event(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $this->inTenantOrg(fn () => $lesson->summary()->create(['content' => 'Primeira versão.']));

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/summary", [
            'content' => 'Segunda versão.',
        ])->assertRedirect();

        $this->inTenantOrg(function () use ($lesson): void {
            $event = AuditEvent::where('event', 'lesson.summary_saved')->where('subject_id', $lesson->id)->sole();
            $this->assertSame($this->teacher->id, $event->causer_id);
            // The first save (Preparation -> Prepared) is its own event and
            // must not fire again on this second, same-status save.
            $this->assertSame(0, AuditEvent::where('event', 'lesson.prepared')->count());
        });
    }

    // ------------------------------------------------------------ lesson taught + attendance

    #[Test]
    public function marking_a_lesson_taught_also_consolidates_and_audits_attendance(): void
    {
        $this->enroll('Aluno Um');
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);

        // The individual "marcar como lecionada" button consolidates
        // attendance in the same transaction (MarkLessonAsTaught's own
        // default) — both existing events are expected from this one call.
        $this->asTeacher()->post("/lessons/{$lesson->ulid}/mark-taught")->assertRedirect();

        $this->inTenantOrg(function () use ($lesson): void {
            AuditEvent::where('event', 'lesson.taught')->where('subject_id', $lesson->id)->sole();
        });

        $this->inTenantOrg(function () use ($lesson): void {
            $event = AuditEvent::where('event', 'lesson.attendance_recorded')
                ->where('subject_id', $lesson->id)
                ->sole();
            $this->assertSame($this->teacher->id, $event->causer_id);
            $this->assertSame(1, $event->properties['present']);
        });
    }

    // ------------------------------------------------------------ instrument

    /**
     * @return array<string, mixed>
     */
    protected function instrumentPayload(): array
    {
        $period = $this->inTenantOrg(fn () => $this->schoolClass->academicYear->periods()->firstOrCreate(
            ['sequence' => 1],
            ['label' => '1.º Período', 'kind' => 'term', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-30', 'status' => 'open'],
        ));

        return [
            'title' => 'Teste 1',
            'academic_period_id' => $period->id,
            'instrument_type_id' => 0,
            'custom_instrument_type_name' => 'Ficha de Trabalho',
            'applied_on' => '2026-10-15',
            'submission_intent' => 'prepare',
            'purpose' => 'summative',
            'counts_toward_classification' => true,
            'total_points' => 100,
            'allow_bonus' => false,
            'items' => [
                ['code' => 'Q1', 'points_possible' => 100],
            ],
        ];
    }

    #[Test]
    public function creating_an_instrument_records_an_audit_event(): void
    {
        $this->asTeacher()
            ->post("/classes/{$this->schoolClass->ulid}/instruments", $this->instrumentPayload())
            ->assertRedirect();

        $this->inTenantOrg(function (): void {
            $event = AuditEvent::where('event', 'instrument.created')->sole();
            $this->assertSame($this->teacher->id, $event->causer_id);
            $this->assertSame($this->schoolClass->id, $event->properties['class_id']);
        });
    }

    #[Test]
    public function a_failing_instrument_creation_records_no_audit_event(): void
    {
        $payload = $this->instrumentPayload();
        unset($payload['title']);

        $this->asTeacher()
            ->post("/classes/{$this->schoolClass->ulid}/instruments", $payload)
            ->assertSessionHasErrors('title');

        $this->inTenantOrg(fn () => $this->assertSame(0, AuditEvent::where('event', 'instrument.created')->count()));
    }

    // ------------------------------------------------------------ scores

    /**
     * @return array{instrument: Instrument, item: InstrumentItem, enrollment: Enrollment}
     */
    protected function scoringScenario(): array
    {
        $enrollment = $this->enroll('Aluno da Grelha');

        $this->asTeacher()
            ->post("/classes/{$this->schoolClass->ulid}/instruments", $this->instrumentPayload())
            ->assertRedirect();

        return $this->inTenantOrg(function () use ($enrollment): array {
            $instrument = Instrument::where('class_id', $this->schoolClass->id)->firstOrFail();
            $item = $instrument->items()->firstOrFail();

            return ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment];
        });
    }

    #[Test]
    public function saving_scores_records_an_event_and_a_second_changed_save_records_another(): void
    {
        ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment] = $this->scoringScenario();

        $this->asTeacher()->post("/instruments/{$instrument->ulid}/scores", [
            'cells' => [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'lock_version' => 0,
                'points_earned' => 80,
            ]],
        ])->assertRedirect();

        $this->inTenantOrg(function () use ($instrument): void {
            $event = AuditEvent::where('event', 'scores.recorded')->where('subject_id', $instrument->id)->sole();
            $this->assertSame(1, $event->properties['created']);
            $this->assertSame(0, $event->properties['updated']);
        });

        // A second, genuinely changed save on the same cell — the lock
        // version the client now holds is 1, from the first save above.
        $this->asTeacher()->post("/instruments/{$instrument->ulid}/scores", [
            'cells' => [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'lock_version' => 1,
                'points_earned' => 90,
            ]],
        ])->assertRedirect();

        $this->inTenantOrg(function () use ($instrument): void {
            $events = AuditEvent::where('event', 'scores.recorded')->where('subject_id', $instrument->id)->orderBy('id')->get();
            $this->assertCount(2, $events);
            $this->assertSame(0, $events->last()->properties['created']);
            $this->assertSame(1, $events->last()->properties['updated']);

            // Never a student's name or an actual grade value in the trail.
            foreach ($events as $event) {
                $payload = json_encode($event->properties);
                $this->assertStringNotContainsString('Aluno da Grelha', (string) $payload);
                $this->assertStringNotContainsString('80', (string) $payload);
                $this->assertStringNotContainsString('90', (string) $payload);
            }
        });
    }

    #[Test]
    public function a_stale_scores_write_records_no_scores_recorded_event(): void
    {
        ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment] = $this->scoringScenario();

        $this->asTeacher()->post("/instruments/{$instrument->ulid}/scores", [
            'cells' => [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'lock_version' => 0,
                'points_earned' => 10,
            ]],
        ])->assertRedirect();

        // Resent with the SAME (now stale) lock_version 0.
        $this->asTeacher()->post("/instruments/{$instrument->ulid}/scores", [
            'cells' => [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'lock_version' => 0,
                'points_earned' => 20,
            ]],
        ])->assertRedirect();

        $this->inTenantOrg(function () use ($instrument): void {
            $this->assertSame(1, AuditEvent::where('event', 'scores.recorded')->where('subject_id', $instrument->id)->count());
            $this->assertSame(1, AuditEvent::where('event', 'scores.stale_write_rejected')->where('subject_id', $instrument->id)->count());
        });
    }

    // ------------------------------------------------------------ intervention

    #[Test]
    public function registering_an_intervention_records_an_audit_event(): void
    {
        $enrollment = $this->enroll('Aluno com Medida');

        $payload = [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollment->id],
            'intervention_type' => InterventionType::WritingOrganizationSupport->value,
            'domain_relation' => 'none',
            'description' => null,
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
            'legal_framing' => null,
            'confirm_suggested_framing' => false,
            'support_measure_level' => null,
            'support_measure_code' => null,
            'evaluation_adaptation_code' => null,
        ];

        $this->asTeacher()->post("/classes/{$this->schoolClass->ulid}/interventions", $payload)
            ->assertRedirect();

        $this->inTenantOrg(function (): void {
            $event = AuditEvent::where('event', 'intervention.created')->sole();
            $this->assertSame($this->teacher->id, $event->causer_id);
        });
    }

    // ------------------------------------------------------------ evidence record

    #[Test]
    public function creating_a_record_is_audited_without_the_students_name(): void
    {
        $enrollment = $this->enroll('Nome do Registo');

        $payload = [
            'kind' => EvidenceKind::Incident->value,
            'disciplinary_severity' => 'g2',
            'homework_status' => null,
            'participation_level' => null,
            'activity_evaluation' => null,
            'activity_include_in_report' => null,
            'description' => null,
            'occurred_at' => '2026-10-10',
            'enrollment_ids' => [$enrollment->id],
            'domain_id' => null,
        ];

        $this->asTeacher()->post("/classes/{$this->schoolClass->ulid}/records", $payload)
            ->assertRedirect();

        $this->inTenantOrg(function () use ($enrollment): void {
            $event = AuditEvent::where('event', 'record.created')->sole();
            $this->assertSame($this->teacher->id, $event->causer_id);
            $this->assertSame([$enrollment->id], $event->properties['enrollment_ids']);
            $this->assertStringNotContainsString('Nome do Registo', (string) $event->summary);
            $this->assertStringNotContainsString('Nome do Registo', (string) json_encode($event->properties));
        });
    }

    #[Test]
    public function a_failing_record_creation_records_no_audit_event(): void
    {
        // 'kind' missing entirely — the field-shape rules reject it.
        $payload = [
            'kind' => null,
            'disciplinary_severity' => null,
            'homework_status' => null,
            'participation_level' => null,
            'activity_evaluation' => null,
            'activity_include_in_report' => null,
            'description' => null,
            'occurred_at' => null,
            'enrollment_ids' => [],
            'domain_id' => null,
        ];

        $this->asTeacher()->postJson("/classes/{$this->schoolClass->ulid}/records", $payload)
            ->assertStatus(422);

        $this->inTenantOrg(fn () => $this->assertSame(0, AuditEvent::where('event', 'record.created')->count()));
    }

    // ------------------------------------------------------------ visibility

    #[Test]
    public function a_members_real_action_reaches_the_owners_organization_audit_with_the_members_name(): void
    {
        $owner = User::factory()->withoutOrganization()->create();
        $member = User::factory()->withoutOrganization()->create(['name' => 'Colega Membro']);
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach([$owner->id, $member->id], ['joined_at' => now()]);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();

        $year = $this->inTenant($organization, fn () => AcademicYear::factory()->recycle($organization)
            ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']));
        $subject = $this->inTenant($organization, fn () => Subject::factory()->recycle($organization)->create());

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->post('/classes', ['label' => '9.º C', 'academic_year_id' => $year->id, 'subject_id' => $subject->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->get('/activity')
            ->assertInertia(fn ($page) => $page->where('events.0.event', 'class.created'));

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->get('/activity')
            ->assertInertia(fn ($page) => $page->has('events', 0));

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->get('/activity/organization')
            ->assertInertia(fn ($page) => $page
                ->where('events.0.event', 'class.created')
                ->where('events.0.causer', 'Colega Membro'));
    }

    #[Test]
    public function another_teacher_in_the_same_institutional_organization_does_not_see_the_event_in_their_own_activity(): void
    {
        $owner = User::factory()->withoutOrganization()->create();
        $member = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach([$owner->id, $member->id], ['joined_at' => now()]);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();

        app(CurrentOrganization::class)->runFor($organization, function () use ($owner): void {
            app(AuditLog::class)->record(
                'class.created', causer: $owner, summary: 'Turma X criada.',
            );
        });

        // The colleague's own reading: nothing.
        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->get('/activity')
            ->assertInertia(fn ($page) => $page->has('events', 0));

        // The owner's organization-wide reading: the event, with the
        // causer's own name attached.
        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->get('/activity/organization')
            ->assertInertia(fn ($page) => $page
                ->has('events', 1)
                ->where('events.0.event', 'class.created')
                ->where('events.0.causer', $owner->name));
    }

    #[Test]
    public function a_different_organization_entirely_never_sees_the_event(): void
    {
        $stranger = User::factory()->create();

        $this->inTenantOrg(function (): void {
            app(AuditLog::class)->record(
                'class.created', causer: $this->teacher, summary: 'Turma Y criada.',
            );
        });

        $this->actingAs($stranger)->get('/activity')
            ->assertInertia(fn ($page) => $page->has('events', 0));
    }
}
