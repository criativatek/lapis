<?php

namespace Tests\Feature\Interventions;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Intervention;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * «Marcar todas como Em curso» e «Concluir todas as medidas»: o botão
 * individual aplicado à turma inteira, com o servidor a recalcular tudo.
 */
class BulkStatusChangeTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
    }

    private function inTenant(callable $callback, ?User $user = null): mixed
    {
        return app(CurrentOrganization::class)->runFor(($user ?? $this->teacher)->personalOrganization(), $callback);
    }

    private function makeClass(string $label = '7.º A', string $yearStart = '2026-09-14', ?User $owner = null): SchoolClass
    {
        $owner ??= $this->teacher;

        $ids = $this->inTenant(fn (): array => [
            'year' => AcademicYear::factory()->recycle($owner->personalOrganization())
                ->create(['starts_on' => $yearStart, 'ends_on' => ((int) substr($yearStart, 0, 4) + 1).'-06-30'])->id,
            'subject' => Subject::factory()->recycle($owner->personalOrganization())->create()->id,
        ], $owner);

        $this->actingAs($owner)->post('/classes', [
            'label' => $label,
            'academic_year_id' => $ids['year'],
            'subject_id' => $ids['subject'],
        ])->assertRedirect();

        return SchoolClass::withoutGlobalScope('organization')
            ->where('label', $label)
            ->where('academic_year_id', $ids['year'])
            ->firstOrFail();
    }

    private function enrol(SchoolClass $class, string $name): Enrollment
    {
        return $this->inTenant(
            fn (): Enrollment => app(StudentEnrollmentService::class)->enrollNew($class, ['name' => $name, 'status' => EnrollmentStatus::Active->value]),
        );
    }

    /**
     * @param  list<Enrollment>  $participants
     */
    private function measure(SchoolClass $class, InterventionStatus $status, array $participants = [], ?User $owner = null): Intervention
    {
        $owner ??= $this->teacher;

        return $this->inTenant(function () use ($class, $status, $participants, $owner): Intervention {
            $intervention = Intervention::create([
                'class_id' => $class->id,
                'target_type' => $participants === [] ? InterventionTargetType::SchoolClass : InterventionTargetType::Student,
                'intervention_type' => InterventionType::Other,
                'intervention_type_label' => 'Medida importada',
                'title' => 'Medida importada',
                'domain_relation' => 'none',
                'description_source' => 'import',
                'status' => $status,
                'started_on' => '2026-10-01',
                'review_on' => '2026-12-01',
                'available_for_reports' => true,
                'include_in_report' => true,
                'created_by' => $owner->getKey(),
            ]);
            $intervention->participants()->sync(array_map(fn (Enrollment $enrollment) => $enrollment->id, $participants));

            return $intervention;
        }, $owner);
    }

    private function statusOf(Intervention $intervention, ?User $owner = null): InterventionStatus
    {
        return $this->inTenant(fn () => Intervention::findOrFail($intervention->id)->status, $owner);
    }

    private function statusEvents(?string $event = null): int
    {
        return AuditEvent::query()
            ->whereIn('event', $event === null ? ['intervention.status_changed', 'intervention.reopened'] : [$event])
            ->count();
    }

    private function bulk(SchoolClass $class, string $from, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->teacher)->post("/classes/{$class->ulid}/interventions/bulk-status", ['from' => $from]);
    }

    // ------------------------------------------------------------ pré-visualização

    #[Test]
    public function the_page_previews_measures_and_students_for_the_whole_class_regardless_of_filters(): void
    {
        $class = $this->makeClass();
        $ana = $this->enrol($class, 'Ana');
        $bruno = $this->enrol($class, 'Bruno');
        $this->enrol($class, 'Carla');

        $this->measure($class, InterventionStatus::New, [$ana]);
        $this->measure($class, InterventionStatus::New, [$ana, $bruno]);
        $this->measure($class, InterventionStatus::InProgress, [$bruno]);
        $this->measure($class, InterventionStatus::Concluded, [$bruno]);
        $this->measure($class, InterventionStatus::Suspended, [$bruno]);

        // Um filtro que esconde tudo não muda o que o lote abrange.
        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}/interventions?status=concluded")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('bulkStatus.new.measures', 2)
                ->where('bulkStatus.new.enrollment_ids', [$ana->id, $bruno->id])
                ->where('bulkStatus.in_progress.measures', 1)
                ->where('bulkStatus.in_progress.enrollment_ids', [$bruno->id]));
    }

    #[Test]
    public function a_class_wide_measure_covers_every_active_student(): void
    {
        $class = $this->makeClass();
        $ana = $this->enrol($class, 'Ana');
        $bruno = $this->enrol($class, 'Bruno');
        $this->measure($class, InterventionStatus::New);

        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}/interventions")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('bulkStatus.new.measures', 1)
                ->where('bulkStatus.new.enrollment_ids', [$ana->id, $bruno->id]));
    }

    // ------------------------------------------------------------------ execução

    #[Test]
    public function planned_measures_become_in_progress_and_the_other_states_are_preserved(): void
    {
        $class = $this->makeClass();
        $ana = $this->enrol($class, 'Ana');

        $planned = [$this->measure($class, InterventionStatus::New, [$ana]), $this->measure($class, InterventionStatus::New, [$ana])];
        $running = $this->measure($class, InterventionStatus::InProgress, [$ana]);
        $concluded = $this->measure($class, InterventionStatus::Concluded, [$ana]);
        $suspended = $this->measure($class, InterventionStatus::Suspended, [$ana]);
        $cancelled = $this->measure($class, InterventionStatus::Cancelled, [$ana]);

        $this->bulk($class, 'new')->assertRedirect();

        foreach ($planned as $intervention) {
            $this->assertSame(InterventionStatus::InProgress, $this->statusOf($intervention));
        }
        $this->assertSame(InterventionStatus::InProgress, $this->statusOf($running));
        $this->assertSame(InterventionStatus::Concluded, $this->statusOf($concluded));
        $this->assertSame(InterventionStatus::Suspended, $this->statusOf($suspended));
        $this->assertSame(InterventionStatus::Cancelled, $this->statusOf($cancelled));
        // Só as duas planeadas geraram evento.
        $this->assertSame(2, $this->statusEvents());
    }

    #[Test]
    public function in_progress_measures_are_concluded_with_the_date_and_the_review_date_cleared(): void
    {
        $class = $this->makeClass();
        $ana = $this->enrol($class, 'Ana');

        $running = $this->measure($class, InterventionStatus::InProgress, [$ana]);
        $planned = $this->measure($class, InterventionStatus::New, [$ana]);
        $suspended = $this->measure($class, InterventionStatus::Suspended, [$ana]);

        $this->bulk($class, 'in_progress')->assertRedirect();

        $this->inTenant(function () use ($running, $planned, $suspended): void {
            $fresh = Intervention::findOrFail($running->id);
            $this->assertSame(InterventionStatus::Concluded, $fresh->status);
            $this->assertSame(now()->toDateString(), $fresh->concluded_on?->toDateString());
            $this->assertNull($fresh->review_on);

            $this->assertSame(InterventionStatus::New, Intervention::findOrFail($planned->id)->status);
            $this->assertNull(Intervention::findOrFail($planned->id)->concluded_on);
            $this->assertSame(InterventionStatus::Suspended, Intervention::findOrFail($suspended->id)->status);
        });
    }

    #[Test]
    public function each_change_is_audited_with_the_user_and_the_time(): void
    {
        $class = $this->makeClass();
        $ana = $this->enrol($class, 'Ana');
        $measure = $this->measure($class, InterventionStatus::New, [$ana]);

        $this->bulk($class, 'new')->assertRedirect();

        $event = AuditEvent::query()->where('event', 'intervention.status_changed')->sole();
        $this->assertSame($this->teacher->id, $event->causer_id);
        $this->assertSame($measure->id, (int) $event->subject_id);
        $this->assertSame('Intervention', $event->subject_type);
        $this->assertSame('new', $event->properties['from']);
        $this->assertSame('in_progress', $event->properties['to']);
        $this->assertTrue($event->properties['bulk']);
        $this->assertSame($class->id, $event->properties['class_id']);
        $this->assertNotNull($event->created_at);
    }

    #[Test]
    public function the_bulk_event_has_the_same_shape_as_the_individual_one(): void
    {
        $class = $this->makeClass();
        $ana = $this->enrol($class, 'Ana');
        $bulkMeasure = $this->measure($class, InterventionStatus::New, [$ana]);
        $singleMeasure = $this->measure($class, InterventionStatus::New, [$ana]);

        $this->actingAs($this->teacher)->patch("/interventions/{$singleMeasure->ulid}", ['status' => 'in_progress'])->assertRedirect();
        $this->bulk($class, 'new');

        $events = AuditEvent::query()->where('event', 'intervention.status_changed')->get()->keyBy('subject_id');
        $single = $events[$singleMeasure->id]->properties;
        $bulk = $events[$bulkMeasure->id]->properties;

        $this->assertTrue($bulk['bulk']);
        unset($bulk['bulk']);
        $this->assertEquals($single, $bulk);
        $this->assertSame($events[$singleMeasure->id]->summary, $events[$bulkMeasure->id]->summary);
    }

    #[Test]
    public function existing_follow_up_reviews_are_left_untouched(): void
    {
        $class = $this->makeClass();
        $ana = $this->enrol($class, 'Ana');
        $measure = $this->measure($class, InterventionStatus::InProgress, [$ana]);

        $this->actingAs($this->teacher)->post("/interventions/{$measure->ulid}/reviews", [
            'reviewed_on' => '2026-11-15',
            'effectiveness' => 'partially_effective',
            'notes' => 'Melhoria na organização.',
        ])->assertRedirect();

        $before = $this->inTenant(fn () => Intervention::findOrFail($measure->id)->reviews()->get()->toArray());

        $this->bulk($class, 'in_progress')->assertRedirect();

        $after = $this->inTenant(fn () => Intervention::findOrFail($measure->id)->reviews()->get()->toArray());
        $this->assertCount(1, $after);
        $this->assertEquals($before, $after);
    }

    // ------------------------------------------------------------------- isolamento

    #[Test]
    public function other_classes_and_other_academic_years_are_not_touched(): void
    {
        $class = $this->makeClass('7.º A', '2026-09-14');
        $sibling = $this->makeClass('7.º B', '2026-09-14');
        $lastYear = $this->makeClass('7.º A', '2025-09-15');
        $ana = $this->enrol($class, 'Ana');

        $own = $this->measure($class, InterventionStatus::New, [$ana]);
        $inSibling = $this->measure($sibling, InterventionStatus::New);
        $inLastYear = $this->measure($lastYear, InterventionStatus::New);

        $this->bulk($class, 'new')->assertRedirect();

        $this->assertSame(InterventionStatus::InProgress, $this->statusOf($own));
        $this->assertSame(InterventionStatus::New, $this->statusOf($inSibling));
        $this->assertSame(InterventionStatus::New, $this->statusOf($inLastYear));
    }

    #[Test]
    public function another_organization_can_neither_run_nor_be_touched(): void
    {
        $class = $this->makeClass();
        $ana = $this->enrol($class, 'Ana');
        $mine = $this->measure($class, InterventionStatus::New, [$ana]);

        $stranger = User::factory()->create();
        $strangerClass = $this->makeClass('7.º A', '2026-09-14', $stranger);
        $theirs = $this->measure($strangerClass, InterventionStatus::New, [], $stranger);

        // A turma de outra organização não é alcançável.
        $this->bulk($class, 'new', $stranger)->assertNotFound();
        $this->assertSame(InterventionStatus::New, $this->statusOf($mine));

        // E o lote de uma organização não toca na outra.
        $this->bulk($class, 'new')->assertRedirect();
        $this->assertSame(InterventionStatus::InProgress, $this->statusOf($mine));
        $this->assertSame(InterventionStatus::New, $this->statusOf($theirs, $stranger));
    }

    #[Test]
    public function a_colleague_who_does_not_teach_the_class_is_refused_and_nothing_changes(): void
    {
        $class = $this->makeClass();
        $measure = $this->measure($class, InterventionStatus::New);

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->teacher->personalOrganization(), ['joined_at' => now()]);

        $this->withSession(['organization_id' => $this->teacher->personalOrganization()->id])
            ->actingAs($colleague)
            ->post("/classes/{$class->ulid}/interventions/bulk-status", ['from' => 'new'])
            ->assertForbidden();

        $this->assertSame(InterventionStatus::New, $this->statusOf($measure));
        $this->assertSame(0, $this->statusEvents());
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $class = $this->makeClass();
        auth()->logout();

        $this->post("/classes/{$class->ulid}/interventions/bulk-status", ['from' => 'new'])->assertRedirect('/login');
    }

    // --------------------------------------------------- validação, idempotência, parcial

    #[Test]
    public function only_the_two_documented_operations_are_accepted(): void
    {
        $class = $this->makeClass();
        $measure = $this->measure($class, InterventionStatus::Suspended);

        foreach (['suspended', 'concluded', 'cancelled', 'bogus', ''] as $from) {
            $this->bulk($class, $from)->assertSessionHasErrors('from');
        }

        // Alvo ou ids vindos do cliente são ignorados: só `from` conta.
        $this->actingAs($this->teacher)
            ->post("/classes/{$class->ulid}/interventions/bulk-status", ['from' => 'new', 'to' => 'cancelled', 'ids' => [$measure->id]])
            ->assertRedirect();

        $this->assertSame(InterventionStatus::Suspended, $this->statusOf($measure));
    }

    #[Test]
    public function repeating_the_request_changes_nothing_and_duplicates_no_events(): void
    {
        $class = $this->makeClass();
        $ana = $this->enrol($class, 'Ana');
        $this->measure($class, InterventionStatus::New, [$ana]);
        $this->measure($class, InterventionStatus::New, [$ana]);

        $this->bulk($class, 'new')->assertRedirect();
        $this->assertSame(2, $this->statusEvents());

        // Duplo clique / reenvio: a segunda execução já não encontra nada
        // elegível e não escreve eventos.
        $this->bulk($class, 'new')->assertRedirect();
        $this->assertSame(2, $this->statusEvents());
    }

    #[Test]
    public function the_server_recalculates_so_a_measure_that_moved_since_the_preview_is_ignored(): void
    {
        $class = $this->makeClass();
        $ana = $this->enrol($class, 'Ana');
        $stillPlanned = $this->measure($class, InterventionStatus::New, [$ana]);
        $movedMeanwhile = $this->measure($class, InterventionStatus::New, [$ana]);

        // O professor viu «2 medidas»; entretanto uma foi suspensa à mão.
        $this->actingAs($this->teacher)->patch("/interventions/{$movedMeanwhile->ulid}", ['status' => 'suspended'])->assertRedirect();
        $this->assertSame(1, $this->statusEvents());

        $this->bulk($class, 'new')->assertRedirect();

        $this->assertSame(InterventionStatus::InProgress, $this->statusOf($stillPlanned));
        $this->assertSame(InterventionStatus::Suspended, $this->statusOf($movedMeanwhile));
        $this->assertSame(2, $this->statusEvents());
    }

    #[Test]
    public function the_whole_batch_is_atomic(): void
    {
        $class = $this->makeClass();
        $ana = $this->enrol($class, 'Ana');
        $first = $this->measure($class, InterventionStatus::New, [$ana]);
        $second = $this->measure($class, InterventionStatus::New, [$ana]);

        // Falha ao auditar a segunda: nada do lote pode ficar a meio.
        $this->app->bind(AuditLog::class, fn () => new class extends AuditLog
        {
            private int $calls = 0;

            public function record(string $event, ?Model $subject = null, ?User $causer = null, ?string $summary = null, array $properties = []): AuditEvent
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('falha simulada');
                }

                return parent::record($event, $subject, $causer, $summary, $properties);
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->bulk($class, 'new');
            $this->fail('A falha simulada devia propagar-se.');
        } catch (RuntimeException $exception) {
            $this->assertSame('falha simulada', $exception->getMessage());
        }

        $this->assertSame(InterventionStatus::New, $this->statusOf($first));
        $this->assertSame(InterventionStatus::New, $this->statusOf($second));
        $this->assertSame(0, $this->statusEvents());
    }

    // ------------------------------------------------------- ações individuais intactas

    #[Test]
    public function the_individual_actions_still_work_unchanged(): void
    {
        $class = $this->makeClass();
        $ana = $this->enrol($class, 'Ana');
        $measure = $this->measure($class, InterventionStatus::New, [$ana]);

        $this->actingAs($this->teacher)->patch("/interventions/{$measure->ulid}", ['status' => 'concluded', 'notes' => 'Feito.'])->assertRedirect();
        $this->assertSame(InterventionStatus::Concluded, $this->statusOf($measure));
        $this->assertSame(1, $this->inTenant(fn () => Intervention::findOrFail($measure->id)->reviews()->count()));

        $this->actingAs($this->teacher)->patch("/interventions/{$measure->ulid}", ['status' => 'in_progress'])->assertRedirect();
        $this->assertSame(1, $this->statusEvents('intervention.reopened'));
        $this->assertNull($this->inTenant(fn () => Intervention::findOrFail($measure->id)->concluded_on));
    }
}
