<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriodKind;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pautas de Avaliação (Fatia 2): a única vista — quantitativo, apreciação
 * qualitativa por domínio e classificação sugerida vs. decidida chegam todos
 * de uma vez. O ecrã só oculta grupos; nada aqui deve alterar o que
 * BuildEvaluationSheet devolveu (essa lógica já está coberta por
 * EvaluationSheetTest — este ficheiro cobre a rota, o controller e a
 * isolação por organização).
 */
class EvaluationSheetControllerTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): User
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return $teacher;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(User $teacher, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), $callback);
    }

    private function classUlid(User $teacher): string
    {
        return $this->asTenant($teacher, fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()->ulid);
    }

    private function open(User $teacher, string $classUlid, ?string $period = null): TestResponse
    {
        $path = $period === null
            ? "/classes/{$classUlid}/pauta-avaliacao"
            : "/classes/{$classUlid}/pauta-avaliacao/{$period}";

        return $this->actingAs($teacher)->get($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        return $page['props'];
    }

    #[Test]
    public function it_renders_with_domains_and_students_from_the_read_model(): void
    {
        $teacher = $this->seedDemo();

        $response = $this->open($teacher, $this->classUlid($teacher));
        $response->assertInertia(fn ($page) => $page->component('evaluation-sheets/Show'));

        $props = $this->props($response);

        $this->assertNotNull($props['sheet']);
        $this->assertSame(
            ['Oralidade', 'Leitura', 'Escrita', 'Gramática', 'Educação Literária'],
            array_column($props['sheet']['domains'], 'name'),
        );
        $this->assertNotEmpty($props['sheet']['students']);

        $carolina = collect($props['sheet']['students'])->firstWhere('name', 'Carolina Nunes');
        $this->assertNotNull($carolina);
        $this->assertArrayHasKey('overall', $carolina);
        $this->assertArrayHasKey('domains', $carolina);
        $this->assertArrayHasKey('classification', $carolina);
        $this->assertArrayHasKey('coverage', $carolina);
    }

    #[Test]
    public function the_period_selector_uses_the_dynamic_kind_label_never_a_hardcoded_word(): void
    {
        $teacher = $this->seedDemo();

        $response = $this->open($teacher, $this->classUlid($teacher));
        $props = $this->props($response);

        [$class, $period] = $this->asTenant($teacher, function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return [$class, $class->academicYear->periods()->where('sequence', 1)->firstOrFail()];
        });

        $this->assertSame(AcademicPeriodKind::Semester, $period->kind);

        $selected = collect($props['periods'])->firstWhere('selected', true);
        $this->assertSame('Semestre', $selected['kind_label']);
        $this->assertSame($period->label, $selected['label']);
    }

    #[Test]
    public function without_a_period_in_the_url_it_falls_back_to_the_first_period(): void
    {
        $teacher = $this->seedDemo();

        $withoutPeriod = $this->props($this->open($teacher, $this->classUlid($teacher)));

        $firstPeriodUlid = $this->asTenant($teacher, function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return $class->academicYear->periods()->orderBy('sequence')->firstOrFail()->ulid;
        });

        $selected = collect($withoutPeriod['periods'])->firstWhere('selected', true);
        $this->assertSame($firstPeriodUlid, $selected['ulid']);

        $explicit = $this->props($this->open($teacher, $this->classUlid($teacher), $firstPeriodUlid));
        $this->assertSame(
            $withoutPeriod['sheet']['academic_period_id'],
            $explicit['sheet']['academic_period_id'],
        );
    }

    #[Test]
    public function domain_colours_are_present_and_stable_across_two_requests(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        $first = $this->props($this->open($teacher, $classUlid))['sheet']['domains'];
        $second = $this->props($this->open($teacher, $classUlid))['sheet']['domains'];

        $this->assertNotEmpty($first);

        foreach ($first as $domain) {
            $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $domain['color']);
        }

        $firstColorsById = collect($first)->pluck('color', 'domain_id');
        $secondColorsById = collect($second)->pluck('color', 'domain_id');

        $this->assertSame($firstColorsById->all(), $secondColorsById->all());
    }

    #[Test]
    public function another_teacher_without_access_to_the_class_is_refused(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get("/classes/{$classUlid}/pauta-avaliacao")
            ->assertNotFound();
    }

    #[Test]
    public function the_route_is_gated_by_the_results_module(): void
    {
        // Same middleware group as results.*/classifications.*/avaliacoes
        // intercalares — no separate map to drift out of sync with it.
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertNotFalse($routes);
        $this->assertStringContainsString(
            "Route::get('classes/{class}/pauta-avaliacao/{period?}', [EvaluationSheetController::class, 'show'])->name('evaluation-sheets.show');",
            $routes,
        );

        $resultsGroupStart = strpos($routes, "Route::middleware('module:results')->group(");
        $evaluationSheetsRoute = strpos($routes, "->name('evaluation-sheets.show')");
        $nextGroupBoundary = strpos($routes, "Route::middleware('module:reports')->group(");

        $this->assertNotFalse($resultsGroupStart);
        $this->assertNotFalse($evaluationSheetsRoute);
        $this->assertNotFalse($nextGroupBoundary);
        $this->assertGreaterThan($resultsGroupStart, $evaluationSheetsRoute);
        $this->assertLessThan($nextGroupBoundary, $evaluationSheetsRoute);
    }
}
