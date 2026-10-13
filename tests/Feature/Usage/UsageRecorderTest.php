<?php

namespace Tests\Feature\Usage;

use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageFailureCode;
use App\Support\Usage\UsageFeature;
use App\Support\Usage\UsageRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

/**
 * O recorder, ao nível do pedido: o que grava, o que NUNCA grava, e que uma
 * falha na medição nunca estraga o trabalho do professor.
 */
class UsageRecorderTest extends TestCase
{
    use RefreshDatabase, SetsUsageCoverage;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // O relógio ANTES de criar dados.
        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
        $this->startUsageCollectionAt('2026-10-01 00:00:00');

        $this->teacher = User::factory()->create();

        Route::middleware(['web', 'auth', 'organization'])->group(function (): void {
            Route::post('/_usage/summary', function (UsageRecorder $recorder) {
                $recorder->action(UsageEventType::LessonSummarySaved, 'lesson-1');

                return response('ok');
            });
            Route::post('/_usage/items', function (UsageRecorder $recorder) {
                $recorder->action(UsageEventType::StudentsImported, 'roster:abc', 100);

                return response('ok');
            });
            Route::post('/_usage/view', function (UsageRecorder $recorder) {
                $recorder->view(UsageFeature::Lessons);

                return response('ok');
            });
            Route::post('/_usage/failure', function (UsageRecorder $recorder) {
                $recorder->failure(UsageFeature::ImportExport, UsageFailureCode::Database, 'data-imports.confirm');

                return response('ok');
            });
            Route::post('/_usage/invalid', function (UsageRecorder $recorder) {
                $recorder->action(UsageEventType::FeatureViewed);
                $recorder->action(UsageEventType::OperationFailed);
                $recorder->action(UsageEventType::StudentsImported, 'x', 0);

                return response('ok');
            });
            Route::post('/_usage/rolled-back', function (UsageRecorder $recorder) {
                try {
                    DB::transaction(function () use ($recorder): void {
                        $recorder->action(UsageEventType::LessonSummarySaved, 'lesson-2');

                        throw new \RuntimeException('rollback');
                    });
                } catch (\RuntimeException) {
                }

                return response('ok');
            });
        });
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function asTeacher(): self
    {
        return $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->teacher->personalOrganization()->id]);
    }

    #[Test]
    public function an_action_is_recorded_with_the_right_columns(): void
    {
        $this->asTeacher()->post('/_usage/items')->assertOk();

        $event = UsageEvent::query()->sole();

        $this->assertSame($this->teacher->id, $event->user_id);
        $this->assertSame($this->teacher->personalOrganization()->id, $event->organization_id);
        $this->assertSame(UsageEventType::StudentsImported, $event->event);
        $this->assertSame(UsageFeature::ImportExport, $event->feature);
        $this->assertSame('action', $event->kind->value);
        $this->assertSame(100, $event->item_count);
        $this->assertNull($event->failure_code);
        $this->assertSame('2026-10-12', $event->occurred_on->toDateString());
        $this->assertSame('2026-10-12 10:00:00', $event->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame(64, strlen($event->dedupe_key));
    }

    #[Test]
    public function a_view_and_a_failure_are_recorded_with_the_right_columns(): void
    {
        $this->asTeacher()->post('/_usage/view')->assertOk();
        $this->asTeacher()->post('/_usage/failure')->assertOk();

        $view = UsageEvent::query()->where('kind', 'view')->sole();
        $this->assertSame(UsageFeature::Lessons, $view->feature);
        $this->assertNull($view->item_count);
        $this->assertNull($view->failure_code);

        $failure = UsageEvent::query()->where('kind', 'failure')->sole();
        $this->assertSame(UsageFeature::ImportExport, $failure->feature);
        $this->assertSame(UsageFailureCode::Database, $failure->failure_code);
        $this->assertNull($failure->item_count);
    }

    #[Test]
    public function the_same_record_on_the_same_day_is_one_row_and_the_next_day_is_another(): void
    {
        $this->asTeacher()->post('/_usage/summary');
        $this->asTeacher()->post('/_usage/summary');
        $this->asTeacher()->post('/_usage/view');
        $this->asTeacher()->post('/_usage/view');

        $this->assertSame(1, UsageEvent::query()->where('kind', 'action')->count());
        $this->assertSame(1, UsageEvent::query()->where('kind', 'view')->count());

        $this->travelTo(Carbon::parse('2026-10-13 09:00:00', 'Europe/Lisbon'));

        $this->asTeacher()->post('/_usage/summary');

        $this->assertSame(2, UsageEvent::query()->where('kind', 'action')->count());
    }

    #[Test]
    public function an_impersonated_request_records_nothing(): void
    {
        $this->asTeacher()->withSession(['impersonator_id' => 999])->post('/_usage/summary')->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function nothing_is_recorded_without_a_user(): void
    {
        app(UsageRecorder::class)->action(UsageEventType::LessonSummarySaved, 'lesson-1');
        app(UsageRecorder::class)->view(UsageFeature::Lessons);

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function a_person_who_opted_out_is_not_recorded(): void
    {
        $this->teacher->forceFill(['usage_opted_out_at' => now()])->save();

        $this->asTeacher()->post('/_usage/summary')->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function a_rolled_back_transaction_leaves_no_trace(): void
    {
        $this->asTeacher()->post('/_usage/rolled-back')->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function collection_switched_off_records_nothing(): void
    {
        // Desligada = nenhum período aberto (não há chave de configuração).
        DB::table('usage_collection_periods')->update(['ended_at' => now(), 'is_open' => null]);

        $this->asTeacher()->post('/_usage/summary')->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function a_view_type_is_refused_by_action_and_zero_items_are_refused(): void
    {
        $this->asTeacher()->post('/_usage/invalid')->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function a_broken_usage_table_never_breaks_the_request_and_is_logged_by_name_only(): void
    {
        Schema::drop('usage_events');
        Log::spy();

        $this->asTeacher()->post('/_usage/summary')->assertOk()->assertSee('ok');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'usage.record_failed'
                && $context === ['event' => 'lesson.summary_saved', 'exception' => QueryException::class])
            ->once();
    }

    #[Test]
    public function fingerprint_is_null_without_a_model_and_changes_with_the_fields(): void
    {
        $recorder = app(UsageRecorder::class);

        $this->assertNull($recorder->fingerprint(null, ['name']));

        $before = $recorder->fingerprint($this->teacher, ['name', 'status' => 'a']);
        $same = $recorder->fingerprint($this->teacher, ['name', 'status' => 'a']);
        $changedExtra = $recorder->fingerprint($this->teacher, ['name', 'status' => 'b']);

        $this->teacher->name = 'Outro Nome';
        $changedField = $recorder->fingerprint($this->teacher, ['name', 'status' => 'a']);

        $this->assertSame($before, $same);
        $this->assertNotSame($before, $changedExtra);
        $this->assertNotSame($before, $changedField);
    }

    #[Test]
    public function a_failing_auxiliary_read_returns_the_fallback_and_is_logged_without_its_message(): void
    {
        Log::spy();

        $result = app(UsageRecorder::class)->attempt(
            fn () => throw new \RuntimeException('SELECT com dados que não podem ir para o log'),
            'recurso',
        );

        $this->assertSame('recurso', $result);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'usage.record_failed'
                && $context === ['event' => 'attempt', 'exception' => \RuntimeException::class])
            ->once();
    }

    #[Test]
    public function a_successful_auxiliary_read_returns_its_value(): void
    {
        $this->assertSame(42, app(UsageRecorder::class)->attempt(fn () => 42, 0));
    }
}
