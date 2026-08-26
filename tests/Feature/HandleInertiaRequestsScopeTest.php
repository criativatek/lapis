<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The header context bar's own contradiction (panel review §9): the global
 * `scope` shared prop hardcoded subject/gradeLevel/class to null for every
 * request, even one whose route already resolved a real SchoolClass — so a
 * page with genuine class context in view (like Acompanhamento do Aluno)
 * showed its own subject/turma correctly while the header chips above it
 * said "—" / "Sem disciplina selecionada" for the exact same request.
 *
 * FIXED IN THE SHARED MIDDLEWARE, NOT IN ANY ONE MODULE. This is therefore
 * asserted against two different `{class}`-bound routes from two different
 * modules — never against Acompanhamento do Aluno alone — plus a route with
 * no class in view at all, to prove the fix does not invent context where
 * none exists.
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

    #[Test]
    public function the_scope_prop_reflects_the_route_bound_class_on_the_student_progress_page(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope.class', $class->label)
                ->where('scope.subject', $class->subject->name)
                ->where('scope.gradeLevel', $class->grade_level));
    }

    /**
     * A second, unrelated `{class}`-bound route (Registos), so the fix is
     * proven to live in the shared middleware rather than in anything
     * specific to the Aluno panel.
     */
    #[Test]
    public function the_scope_prop_reflects_the_route_bound_class_on_an_unrelated_class_scoped_route(): void
    {
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/records")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope.class', $class->label)
                ->where('scope.subject', $class->subject->name));
    }

    #[Test]
    public function the_scope_prop_stays_null_on_a_route_with_no_class_in_view(): void
    {
        $this->actingAs($this->teacher)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope.class', null)
                ->where('scope.subject', null)
                ->where('scope.gradeLevel', null));
    }

    /**
     * Period is left disabled deliberately (panel review §9): no route
     * establishes a single canonical "current" period the way it does a
     * class, so this never regresses into a guess.
     */
    #[Test]
    public function the_scope_prop_never_invents_a_period(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment(1);

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('scope.period', null));
    }
}
