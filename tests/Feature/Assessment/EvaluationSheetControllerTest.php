<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriodKind;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\DeriveCustomScale;
use App\Services\Assessment\MigrateClassProfile;
use App\Services\Assessment\ProfileBuilder;
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

    /**
     * Troca a escala do perfil da turma de demonstração por outra, ativa a
     * versão nova e liga-lhe a turma — a troca que um professor faz no
     * formulário do perfil, sem passar pelo fluxo de migração.
     */
    private function switchDemoClassToScale(User $teacher, string $scaleName): void
    {
        $this->asTenant($teacher, function () use ($teacher, $scaleName): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $profile = $class->profileVersion->profile;
            $domains = $profile->currentVersion->domains()->with('domain')->get()
                ->map(fn ($row): array => ['name' => $row->domain->name, 'weight' => (float) $row->weight_percent])
                ->all();

            app(ProfileBuilder::class)->update(
                $profile,
                ['subject_id' => $profile->subject_id, 'name' => $profile->name, 'description' => $profile->description],
                Scale::where('name', $scaleName)->firstOrFail()->id,
                $domains,
                $profile->gradeLevels->pluck('grade_level')->all(),
            );
            $version = app(ActivateProfileVersion::class)->activate($profile->refresh()->draftVersion(), $teacher);
            $class->update(['assessment_profile_version_id' => $version->id]);
        });
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
    public function the_live_sheet_prop_carries_the_elements_of_every_domain(): void
    {
        $teacher = $this->seedDemo();

        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        $this->assertNotEmpty($props['sheet']['students']);

        foreach ($props['sheet']['students'] as $student) {
            foreach ($student['domains'] as $domain) {
                $this->assertIsArray($domain['elements']);
            }
        }
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

        $resultsGroupStart = strpos($routes, "Route::middleware(['module:results', 'usage:assessment'])->group(");
        $evaluationSheetsRoute = strpos($routes, "->name('evaluation-sheets.show')");
        $nextGroupBoundary = strpos($routes, "Route::middleware(['module:reports', 'usage:reports'])->group(");

        $this->assertNotFalse($resultsGroupStart);
        $this->assertNotFalse($evaluationSheetsRoute);
        $this->assertNotFalse($nextGroupBoundary);
        $this->assertGreaterThan($resultsGroupStart, $evaluationSheetsRoute);
        $this->assertLessThan($nextGroupBoundary, $evaluationSheetsRoute);
    }

    #[Test]
    public function a_class_on_a_scale_without_mentions_gets_the_link_to_fix_its_profile(): void
    {
        $teacher = $this->seedDemo();
        $this->switchDemoClassToScale($teacher, 'Percentagem (0 a 100)');

        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        $this->assertSame([], $props['scaleBands']);
        $this->assertStringContainsString('/assessment-profiles/', $props['schoolClass']['profile_edit_url']);
        $this->assertStringEndsWith('/edit', $props['schoolClass']['profile_edit_url']);
    }

    #[Test]
    public function a_class_on_the_level_scale_has_bands_and_no_fix_link(): void
    {
        $teacher = $this->seedDemo();

        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        $this->assertNotEmpty($props['scaleBands']);
        $this->assertNull($props['schoolClass']['profile_edit_url']);
    }

    #[Test]
    public function a_profile_on_a_derived_scale_shows_the_school_mentions_and_freezes_the_scale_on_activation(): void
    {
        $teacher = $this->seedDemo();
        $school = ['Muito insuficiente', 'Insuficiente', 'Suficiente', 'Bom', 'Muito bom'];

        $this->asTenant($teacher, function () use ($teacher, $school): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $profile = $class->profileVersion->profile;
            $system = Scale::where('name', 'Escala 1 a 5')->firstOrFail();
            $derived = app(DeriveCustomScale::class)->for($system, array_map(
                fn (string $label, int $cut, string $code): array => ['label' => $label, 'from_percent' => $cut, 'inovar_code' => $code],
                $school,
                [0, 20, 50, 70, 90],
                ['F', 'I', 'S', 'B', 'MB'],
            ), 3, $profile->name);

            $domains = $profile->currentVersion->domains()->with('domain')->get()
                ->map(fn ($row): array => ['name' => $row->domain->name, 'weight' => (float) $row->weight_percent])
                ->all();
            app(ProfileBuilder::class)->update(
                $profile,
                ['subject_id' => $profile->subject_id, 'name' => $profile->name, 'description' => $profile->description],
                $derived->id,
                $domains,
                $profile->gradeLevels->pluck('grade_level')->all(),
            );
            $version = app(ActivateProfileVersion::class)->activate($profile->refresh()->draftVersion(), $teacher);

            $this->assertNotNull($derived->refresh()->frozen_at, 'A escala derivada congela no primeiro uso por uma versão ativa.');

            app(MigrateClassProfile::class)->migrate(
                SchoolClass::whereKey($class->id)->firstOrFail(),
                $version,
                'Menções da escola.',
                $teacher,
            );
        });

        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        $this->assertSame($school, array_column($props['scaleBands'], 'label'));
        $labels = collect($props['sheet']['students'])
            ->flatMap(fn (array $student): array => array_column($student['domains'], 'scale_level_label'))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $this->assertNotEmpty($labels);
        $this->assertSame([], array_values(array_diff($labels, $school)));
        $this->assertNotContains('Fraco', $labels);
        $this->assertNull($props['schoolClass']['profile_edit_url']);
    }
}
