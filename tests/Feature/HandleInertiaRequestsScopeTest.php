<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AcademicYearStatus;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O `workContext` partilhado que a barra superior lê (PR C, 0.181.0).
 *
 * CHAMA-SE `workContext` E NÃO `scope`: um prop da página com a mesma chave
 * substitui o partilhado, e a página das Classificações já envia o seu
 * `scope` (período/acumulado) — a barra antiga perdia o contexto exatamente
 * aí, em silêncio. O teste da troca a partir das Classificações prova-o.
 *
 * DOIS FACTOS, NENHUMA ADIVINHA. `academicYear` é o ano letivo em vigor;
 * `class` é a turma que a rota ligou — a mesma que o controlador já
 * autorizou — ou `null` numa página sem turma em vista. `classes` são as
 * turmas por que o chip deixa trocar: as do professor, do mesmo ano letivo,
 * sem as arquivadas (menos a atual), com o destino calculado por
 * `ClassSwitcher` — a mesma página na outra turma, ou a página da turma
 * quando a rota atual leva algo que só existe na turma de origem.
 *
 * Afirmado em DUAS rotas de módulos diferentes (Registos e Classificações),
 * para a regra viver no middleware partilhado e não num módulo; e numa rota
 * sem turma, para provar que não se inventa contexto onde não há.
 */
class HandleInertiaRequestsScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function enrollment(int $classNumber): Enrollment
    {
        return $this->asTenant(fn (): Enrollment => $this->schoolClass()->enrollments()->where('class_number', $classNumber)->firstOrFail());
    }

    /**
     * Outra turma do mesmo professor, no mesmo ano e com a mesma disciplina —
     * a vizinha natural no chip.
     */
    private function siblingClass(string $label, ?User $teacher = null, ?AcademicYear $year = null, bool $archived = false): SchoolClass
    {
        $origin = $this->schoolClass();

        return $this->asTenant(function () use ($origin, $label, $teacher, $year, $archived): SchoolClass {
            $class = SchoolClass::create([
                'academic_year_id' => ($year ?? $origin->academicYear)->id,
                'subject_id' => $origin->subject_id,
                'grade_level' => '7.º',
                'label' => $label,
                'status' => 'active',
                'assessment_profile_version_id' => $origin->assessment_profile_version_id,
                'archived_at' => $archived ? now() : null,
            ]);

            $class->teachers()->attach($teacher ?? $this->teacher, ['role' => 'owner']);

            return $class;
        });
    }

    #[Test]
    public function the_scope_prop_reflects_the_route_bound_class_on_the_student_progress_page(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workContext.class.ulid', $class->ulid)
                ->where('workContext.class.label', $class->label)
                ->where('workContext.class.subject', $class->subject->name)
                ->where('workContext.class.gradeLevel', $class->grade_level));
    }

    /**
     * Uma segunda rota com `{class}`, de outro módulo (Registos), para a
     * regra ficar provada no middleware partilhado e não no painel do Aluno.
     */
    #[Test]
    public function the_scope_prop_reflects_the_route_bound_class_on_an_unrelated_class_scoped_route(): void
    {
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/records")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workContext.class.label', $class->label)
                ->where('workContext.class.subject', $class->subject->name));
    }

    #[Test]
    public function the_scope_prop_stays_null_on_a_route_with_no_class_in_view(): void
    {
        $this->actingAs($this->teacher)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workContext.class', null)
                ->where('workContext.classes', [])
                ->whereType('workContext.academicYear', 'string'));
    }

    /**
     * Os chips desligados saíram com as chaves que os alimentavam: a barra
     * não tem período, disciplina solta nem ano de escolaridade solto para
     * mostrar, e nada mais lia estas chaves.
     */
    #[Test]
    public function the_scope_prop_carries_no_period_subject_or_grade_level_keys(): void
    {
        $this->actingAs($this->teacher)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('workContext.period')
                ->missing('workContext.subject')
                ->missing('workContext.hasSubjects')
                ->missing('workContext.gradeLevel'));
    }

    #[Test]
    public function the_class_switcher_lists_the_teachers_classes_of_the_same_year_and_keeps_the_route(): void
    {
        $class = $this->schoolClass();
        $sibling = $this->siblingClass('7.º B');

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/records")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->count('workContext.classes', 2)
                ->where('workContext.classes.0.ulid', $class->ulid)
                ->where('workContext.classes.0.is_current', true)
                ->where('workContext.classes.0.href', route('records.show', $class))
                ->where('workContext.classes.1.ulid', $sibling->ulid)
                ->where('workContext.classes.1.label', '7.º B')
                ->where('workContext.classes.1.subject', $class->subject->name)
                ->where('workContext.classes.1.is_current', false)
                ->where('workContext.classes.1.href', route('records.show', $sibling)));
    }

    /**
     * O mesmo chip a partir das Classificações e da leitura da turma: o
     * destino é a página equivalente, e o período (do ano letivo, não da
     * turma) viaja com ela.
     */
    #[Test]
    public function switching_keeps_classifications_and_results_pages_with_their_period(): void
    {
        $class = $this->schoolClass();
        $sibling = $this->siblingClass('7.º B');
        $period = $this->asTenant(fn () => $class->academicYear->periods()->orderBy('starts_on')->firstOrFail());

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/classifications/{$period->ulid}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workContext.classes.1.href', route('classifications.show', ['class' => $sibling, 'period' => $period])));

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workContext.classes.1.href', route('results.show', ['class' => $sibling])));
    }

    /**
     * Um aluno é desta turma, não da outra: a troca cai para a página da
     * turma de destino em vez de apontar para um 404.
     */
    #[Test]
    public function switching_from_a_page_bound_to_something_of_this_class_falls_back_to_the_class_page(): void
    {
        $class = $this->schoolClass();
        $sibling = $this->siblingClass('7.º B');
        $enrollment = $this->enrollment(1);

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workContext.classes.1.href', route('classes.show', $sibling)));
    }

    #[Test]
    public function the_class_switcher_never_lists_another_teachers_class_an_archived_class_or_another_year(): void
    {
        $class = $this->schoolClass();
        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->siblingClass('7.º C', teacher: $colleague);
        $this->siblingClass('7.º D', archived: true);
        $otherYear = $this->asTenant(fn (): AcademicYear => AcademicYear::create([
            'label' => '2025/26',
            'starts_on' => '2025-09-15',
            'ends_on' => '2026-06-30',
            'status' => AcademicYearStatus::Draft,
        ]));
        $this->siblingClass('7.º E', year: $otherYear);

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/records")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->count('workContext.classes', 1)
                ->where('workContext.classes.0.ulid', $class->ulid));
    }

    /**
     * A turma atual entra sempre, mesmo arquivada — o chip tem de dizer onde
     * se está — mas as outras arquivadas não.
     */
    #[Test]
    public function an_archived_class_in_view_still_names_itself_in_the_switcher(): void
    {
        $archived = $this->siblingClass('7.º D', archived: true);
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$archived->ulid}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workContext.class.ulid', $archived->ulid)
                ->count('workContext.classes', 2)
                ->where('workContext.classes.0.ulid', $class->ulid)
                ->where('workContext.classes.1.ulid', $archived->ulid)
                ->where('workContext.classes.1.is_current', true));
    }
}
