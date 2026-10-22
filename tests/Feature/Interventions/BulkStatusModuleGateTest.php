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
use App\Models\Module;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\StudentEnrollmentService;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regressão da Fatia D (D6): as operações em lote das medidas ficam bloqueadas quando o módulo
 * `interventions` não está disponível.
 *
 * A rota `POST classes/{turma}/interventions/bulk-status` vive no grupo `module:interventions`. Isso é o
 * que impede uma organização sem o módulo — por plano, por override ou por assinatura suspensa — de mudar
 * o estado de medidas em massa. Nenhum teste o afirmava: o `BulkStatusChangeTest` prova o que o lote faz
 * quando pode, não que não o faz quando não pode. O que importa aqui é que NADA muda: nem um estado, nem um
 * evento de auditoria.
 */
class BulkStatusModuleGateTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->subscribe('base');
    }

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    private function subscribe(string $plan, SubscriptionStatus $status = SubscriptionStatus::Active): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->teacher->personalOrganization()->id],
            ['plan_id' => Plan::where('key', $plan)->firstOrFail()->id, 'status' => $status, 'starts_at' => Carbon::parse('2026-01-01 00:00:00'), 'ends_at' => null],
        );
        app(Entitlements::class)->flush();
    }

    private function disableInterventions(): void
    {
        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->teacher->personalOrganization()->getKey(),
            'module_id' => Module::where('key', 'interventions')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de override.',
        ]);
        app(Entitlements::class)->flush();
    }

    private function makeClass(): SchoolClass
    {
        $ids = $this->inTenant(fn (): array => [
            'year' => AcademicYear::factory()->recycle($this->teacher->personalOrganization())->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($this->teacher->personalOrganization())->create()->id,
        ]);

        $this->actingAs($this->teacher)->post('/classes', ['label' => '7.º A', 'academic_year_id' => $ids['year'], 'subject_id' => $ids['subject']])->assertRedirect();

        return SchoolClass::withoutGlobalScope('organization')->where('label', '7.º A')->firstOrFail();
    }

    private function measure(SchoolClass $class, InterventionStatus $status): Intervention
    {
        return $this->inTenant(function () use ($class, $status): Intervention {
            $enrollment = app(StudentEnrollmentService::class)->enrollNew($class, ['name' => 'Aluno de Teste', 'status' => EnrollmentStatus::Active->value]);
            $intervention = Intervention::create([
                'class_id' => $class->id,
                'target_type' => InterventionTargetType::Student,
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
                'created_by' => $this->teacher->getKey(),
            ]);
            $intervention->participants()->sync([$enrollment instanceof Enrollment ? $enrollment->id : $enrollment]);

            return $intervention;
        });
    }

    private function statusOf(Intervention $intervention): InterventionStatus
    {
        return $this->inTenant(fn () => Intervention::findOrFail($intervention->id)->status);
    }

    private function statusEvents(): int
    {
        return AuditEvent::query()->whereIn('event', ['intervention.status_changed', 'intervention.reopened'])->count();
    }

    private function bulk(SchoolClass $class, string $from): TestResponse
    {
        return $this->actingAs($this->teacher)->post("/classes/{$class->ulid}/interventions/bulk-status", ['from' => $from]);
    }

    // ================================================================= o controlo: com o módulo, o lote funciona

    #[Test]
    public function with_the_module_the_batch_runs_so_the_gate_tests_below_are_not_vacuous(): void
    {
        $class = $this->makeClass();
        $planned = $this->measure($class, InterventionStatus::New);

        $this->bulk($class, 'new')->assertRedirect();

        $this->assertSame(InterventionStatus::InProgress, $this->statusOf($planned));
        $this->assertGreaterThan(0, $this->statusEvents());
    }

    // ================================================================= módulo desligado

    #[Test]
    public function with_the_module_disabled_the_batch_is_refused_and_nothing_changes(): void
    {
        $class = $this->makeClass();
        $planned = $this->measure($class, InterventionStatus::New);
        $running = $this->measure($class, InterventionStatus::InProgress);
        $before = $this->statusEvents();

        $this->disableInterventions();

        foreach (['new', 'in_progress'] as $operation) {
            $this->bulk($class, $operation)->assertForbidden();
        }

        $this->assertSame(InterventionStatus::New, $this->statusOf($planned));
        $this->assertSame(InterventionStatus::InProgress, $this->statusOf($running));
        $this->assertSame($before, $this->statusEvents(), 'a refused batch leaves no trace of having run');
    }

    #[Test]
    public function with_the_module_disabled_the_page_that_offers_the_batch_is_refused_too(): void
    {
        $class = $this->makeClass();
        $this->measure($class, InterventionStatus::New);

        $this->disableInterventions();

        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}/interventions")
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page->component('ModuleUnavailable'));
    }

    #[Test]
    public function the_refusal_does_not_depend_on_what_the_request_says(): void
    {
        $class = $this->makeClass();
        $planned = $this->measure($class, InterventionStatus::New);

        $this->disableInterventions();

        // A forged body cannot get past a gate that never reads it.
        $this->actingAs($this->teacher)->post("/classes/{$class->ulid}/interventions/bulk-status", ['from' => 'new', 'to' => 'concluded', 'ids' => [$planned->id]])->assertForbidden();
        $this->actingAs($this->teacher)->post("/classes/{$class->ulid}/interventions/bulk-status", [])->assertForbidden();

        $this->assertSame(InterventionStatus::New, $this->statusOf($planned));
    }

    // ================================================================= assinatura suspensa (só de leitura)

    #[Test]
    public function with_a_suspended_subscription_the_batch_is_refused_and_nothing_changes(): void
    {
        $class = $this->makeClass();
        $planned = $this->measure($class, InterventionStatus::New);
        $before = $this->statusEvents();

        $this->subscribe('base', SubscriptionStatus::Suspended);

        $this->bulk($class, 'new')->assertForbidden();

        $this->assertSame(InterventionStatus::New, $this->statusOf($planned));
        $this->assertSame($before, $this->statusEvents());

        // Reading stays: the data do not disappear with the subscription.
        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}/interventions")->assertOk();
    }

    #[Test]
    public function reactivating_the_subscription_gives_the_batch_back(): void
    {
        $class = $this->makeClass();
        $planned = $this->measure($class, InterventionStatus::New);

        $this->subscribe('base', SubscriptionStatus::Suspended);
        $this->bulk($class, 'new')->assertForbidden();

        $this->subscribe('base');
        $this->bulk($class, 'new')->assertRedirect();

        $this->assertSame(InterventionStatus::InProgress, $this->statusOf($planned));
    }
}
