<?php

namespace Tests\Feature\Usage;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Usage\UsageFailureCode;
use App\Support\Usage\UsageFeature;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

/**
 * O middleware `usage:<feature>`, com rotas definidas aqui. As respostas são
 * HTML simples: o que se mede é o pedido e a resposta, não o Inertia.
 */
class RecordUsageMiddlewareTest extends TestCase
{
    use RefreshDatabase, SetsUsageCoverage;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
        $this->startUsageCollectionAt('2026-10-01 00:00:00');

        $this->teacher = User::factory()->create();

        Route::middleware(['web', 'auth', 'organization'])->group(function (): void {
            Route::middleware('usage:lessons')->group(function (): void {
                Route::get('/_mw/page', fn () => response('<html></html>')->header('Content-Type', 'text/html'));
                Route::get('/_mw/json', fn () => response()->json(['ok' => true]));
                Route::get('/_mw/redirect', fn () => redirect('/_mw/page'));
                Route::get('/_mw/boom', function () {
                    throw new \RuntimeException('boom');
                });
                Route::get('/_mw/database', function () {
                    throw new QueryException('sqlite', 'select 1', [], new \Exception('x'));
                });
                Route::get('/_mw/forbidden', fn () => abort(403));
                Route::get('/_mw/aborted', fn () => abort(500));
                Route::get('/_mw/missing', fn () => abort(404));
                Route::get('/_mw/validation', function () {
                    throw ValidationException::withMessages(['a' => 'b']);
                });
                Route::post('/_mw/post', fn () => response('<html></html>')->header('Content-Type', 'text/html'));

                Route::get('/_mw/inner', fn () => response('<html></html>')->header('Content-Type', 'text/html'))
                    ->middleware('usage:class_notebook');
            });

            Route::get('/_mw/unknown', fn () => response('<html></html>')->header('Content-Type', 'text/html'))
                ->middleware('usage:not_a_feature');
        });
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** Sem a versão certa o Inertia responde 409 a um GET com `X-Inertia`. */
    private function asInertiaVisitor(): self
    {
        return $this->asTeacher()->withHeaders([
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ]);
    }

    private function asTeacher(): self
    {
        return $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->teacher->personalOrganization()->id]);
    }

    #[Test]
    public function a_full_page_visit_records_one_view_and_a_second_visit_the_same_day_does_not_add_one(): void
    {
        $this->asTeacher()->get('/_mw/page')->assertOk();

        $view = UsageEvent::query()->sole();
        $this->assertSame('view', $view->kind->value);
        $this->assertSame(UsageFeature::Lessons, $view->feature);

        $this->asTeacher()->get('/_mw/page')->assertOk();

        $this->assertSame(1, UsageEvent::query()->count());
    }

    #[Test]
    public function an_inertia_visit_records_a_view_but_a_partial_reload_and_a_prefetch_do_not(): void
    {
        $this->asInertiaVisitor()->get('/_mw/page', ['X-Inertia' => 'true', 'X-Inertia-Partial-Data' => 'rows', 'X-Inertia-Partial-Component' => 'X'])->assertOk();
        $this->asInertiaVisitor()->get('/_mw/page', ['X-Inertia' => 'true', 'X-Inertia-Partial-Except' => 'rows', 'X-Inertia-Partial-Component' => 'X'])->assertOk();
        // Inertia v3 marca os pré-carregamentos com `Purpose: prefetch`.
        $this->asInertiaVisitor()->get('/_mw/page', ['X-Inertia' => 'true', 'Purpose' => 'prefetch'])->assertOk();
        $this->asTeacher()->get('/_mw/page', ['Sec-Purpose' => 'prefetch;prerender'])->assertOk();
        $this->asTeacher()->get('/_mw/page', ['X-Moz' => 'prefetch'])->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());

        $this->asInertiaVisitor()->get('/_mw/page', ['X-Inertia' => 'true'])->assertOk();

        $this->assertSame(1, UsageEvent::query()->count());
    }

    #[Test]
    public function json_responses_redirects_and_non_get_requests_record_nothing(): void
    {
        $this->asTeacher()->getJson('/_mw/json')->assertOk();
        $this->asTeacher()->get('/_mw/json')->assertOk();
        $this->asTeacher()->get('/_mw/redirect')->assertRedirect();
        $this->asTeacher()->post('/_mw/post')->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function an_unexpected_exception_records_a_failure_with_the_code_and_no_message(): void
    {
        $this->asTeacher()->get('/_mw/boom')->assertStatus(500);

        $failure = UsageEvent::query()->sole();
        $this->assertSame('failure', $failure->kind->value);
        $this->assertSame(UsageFailureCode::Unexpected, $failure->failure_code);
        $this->assertSame(UsageFeature::Lessons, $failure->feature);
    }

    #[Test]
    public function a_query_exception_is_a_database_failure(): void
    {
        $this->asTeacher()->get('/_mw/database')->assertStatus(500);

        $this->assertSame(UsageFailureCode::Database, UsageEvent::query()->sole()->failure_code);
    }

    #[Test]
    public function expected_refusals_and_validation_errors_are_not_failures(): void
    {
        $this->asTeacher()->get('/_mw/forbidden')->assertForbidden();
        $this->asTeacher()->get('/_mw/missing')->assertNotFound();
        // Um abort(500) deliberado é uma resposta HTTP, não uma exceção inesperada.
        $this->asTeacher()->get('/_mw/aborted')->assertStatus(500);
        $this->asTeacher()->get('/_mw/validation')->assertRedirect();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function the_inner_feature_wins_and_there_is_only_one_row(): void
    {
        $this->asTeacher()->get('/_mw/inner')->assertOk();

        $view = UsageEvent::query()->sole();
        $this->assertSame(UsageFeature::ClassNotebook, $view->feature);
    }

    #[Test]
    public function an_unknown_feature_records_nothing_and_does_not_break_the_page(): void
    {
        $this->asTeacher()->get('/_mw/unknown')->assertOk();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function every_usage_parameter_on_a_real_route_is_a_known_feature(): void
    {
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'usage:')) {
                    if (str_starts_with($route->uri(), '_mw/')) {
                        continue;
                    }

                    $this->assertNotNull(
                        UsageFeature::tryFrom(substr($middleware, 6)),
                        "{$route->uri()} usa um parâmetro de utilização desconhecido: {$middleware}",
                    );
                }
            }
        }

        // Ainda nenhuma rota real usa o middleware (é a fatia seguinte): o teste
        // fica a guardar o dia em que usarem um parâmetro que não é uma funcionalidade.
        $this->addToAssertionCount(1);
    }
}
