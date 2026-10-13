<?php

namespace Tests\Feature\Usage;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Enrollment;
use App\Models\EvidenceRecord;
use App\Models\UsageEvent;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Evidence\Concerns\BuildsFollowupFixtures;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

/**
 * O acompanhamento dos registos (0.164.0) e a medição de utilização (0.163.0)
 * no mesmo pedido: o alerta é estado pedagógico e nunca depende da recolha;
 * a medição conta a gravação uma vez e nunca o alerta que ela levantou.
 */
class RecordFollowupUsageTest extends TestCase
{
    use BuildsFollowupFixtures, RefreshDatabase, SetsUsageCoverage;

    private Enrollment $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFollowupFixtures();
        $this->ana = $this->enroll('Ana Costa');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function threeLatenessRecords(): void
    {
        foreach (['2026-10-01', '2026-10-05', '2026-10-12'] as $date) {
            $this->postRecord(['enrollment_ids' => [$this->ana->id], 'occurred_at' => $date])->assertRedirect();
        }
    }

    private function confirmAna(bool $impersonating = false): TestResponse
    {
        $lateness = $this->inTenant($this->organization, fn () => EvidenceRecord::query()->where('enrollment_id', $this->ana->id)->orderBy('occurred_at')->get());

        return $this->actingAs($this->teacher)
            ->withSession(array_filter(['organization_id' => $this->organization->id, 'impersonator_id' => $impersonating ? 999 : null]))
            ->from('/records?view=lateness')
            ->post('/records/acompanhamento/confirmacoes', [
                'class' => $this->schoolClass->ulid,
                'student' => $this->ana->ulid,
                'category' => 'lateness',
                'period' => $this->firstPeriod->ulid,
                'occurrences' => $lateness->map(fn (EvidenceRecord $record): array => ['ulid' => $record->ulid, 'date' => $record->occurred_at->format('Y-m-d')])->all(),
            ]);
    }

    #[Test]
    public function the_save_that_raises_the_alert_is_one_action_and_the_alert_adds_no_metric(): void
    {
        $this->startUsageCollectionAt('2026-10-01 00:00:00');

        $this->threeLatenessRecords();

        $this->assertSame('warning', $this->toast()['type']);
        $this->assertSame(1, $this->alertCount());
        // Three records, three actions — and nothing else: the alert is not an event.
        $this->assertSame(3, UsageEvent::query()->count());
        $this->assertSame(3, UsageEvent::query()->where('event', UsageEventType::RecordCreated->value)->count());

        // A homework batch that crosses the threshold is still ONE action.
        $rui = $this->enroll('Rui Sousa');
        $this->saveHomework('2026-10-01', [$rui->id => 'not_done'])->assertRedirect();
        $this->saveHomework('2026-10-05', [$rui->id => 'not_done'])->assertRedirect();
        $this->saveHomework('2026-10-12', [$rui->id => 'not_done'])->assertRedirect();
        $this->assertSame(2, $this->alertCount());
        $this->assertSame(3, UsageEvent::query()->where('event', UsageEventType::HomeworkBatchSaved->value)->count());

        // Declaring and undoing «Registado no Inovar» are not catalogue actions.
        $this->confirmAna()->assertRedirect();
        $this->assertSame(1, $this->confirmations());
        $this->assertSame(6, UsageEvent::query()->count());
    }

    #[Test]
    public function the_consultation_is_one_view_and_opening_a_case_by_partial_reload_is_not_another(): void
    {
        $this->startUsageCollectionAt('2026-10-01 00:00:00');
        $visitor = fn () => $this->asTeacher()->withHeaders(['X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())]);

        $visitor()->get('/records', ['X-Inertia' => 'true'])->assertOk();
        $visitor()->get('/records?view=lateness&case_class='.$this->schoolClass->ulid.'&case_student='.$this->ana->ulid.'&case_category=lateness', [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'records/Index',
            'X-Inertia-Partial-Data' => 'openCase',
        ])->assertOk();

        $view = UsageEvent::query()->sole();
        $this->assertSame(UsageEventType::FeatureViewed, $view->event);
        $this->assertSame(UsageFeature::Records, $view->feature);
    }

    #[Test]
    public function with_the_collection_off_the_alert_works_and_nothing_is_recorded(): void
    {
        // No collection period: the state every installation starts in.
        $this->threeLatenessRecords();

        $this->assertSame('warning', $this->toast()['type']);
        $this->assertSame(1, $this->alertCount());
        $this->assertNotNull($this->caseFor($this->ana, 'lateness')?->alerted_at);
        $this->confirmAna()->assertRedirect();
        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function a_teacher_who_opted_out_gets_the_alert_and_leaves_no_trace(): void
    {
        $this->startUsageCollectionAt('2026-10-01 00:00:00');
        $this->teacher->forceFill(['usage_opted_out_at' => now()])->save();

        $this->threeLatenessRecords();
        $this->asTeacher()->get('/records')->assertOk();

        $this->assertSame(1, $this->alertCount());
        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function a_support_session_leaves_no_trace_and_never_declares_for_the_teacher(): void
    {
        $this->startUsageCollectionAt('2026-10-01 00:00:00');

        $this->withSession(['impersonator_id' => 999]);
        $this->threeLatenessRecords();

        // The records are the teacher's data — the alert follows them —
        // but the support session is never measured nor allowed to declare.
        $this->assertSame(1, $this->alertCount());
        $this->confirmAna(impersonating: true)->assertForbidden();
        $this->assertSame(0, $this->confirmations());
        $this->assertSame(0, UsageEvent::query()->count());
    }
}
