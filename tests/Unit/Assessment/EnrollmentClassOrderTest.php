<?php

namespace Tests\Unit\Assessment;

use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ClassCohort;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A ordem da turma: pelo número, os sem número no fim, e o `id` a desempatar.
 *
 * Sem o desempate, dois alunos com o mesmo número (ou ambos sem número) ficavam
 * por ordem indefinida — e a grelha, as exportações e o emparelhamento podiam
 * discordar uns dos outros consoante o motor da base de dados.
 */
class EnrollmentClassOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = User::factory()->create()->personalOrganization();

        $this->class = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): SchoolClass => SchoolClass::factory()->recycle($this->organization)->create(),
        );
    }

    /**
     * @param  list<int|null>  $numbers  class numbers, in CREATION order
     * @return list<int> the enrolment ids, in creation order
     */
    protected function enrol(array $numbers): array
    {
        return app(CurrentOrganization::class)->runFor($this->organization, function () use ($numbers): array {
            return array_map(
                fn (?int $number): int => (int) Enrollment::factory()->recycle($this->organization)->create([
                    'class_id' => $this->class->id,
                    'class_number' => $number,
                ])->getKey(),
                $numbers,
            );
        });
    }

    #[Test]
    public function the_class_is_ordered_by_number_whatever_the_creation_order(): void
    {
        $ids = $this->enrol([3, 1, 2]);

        $ordered = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => Enrollment::query()->where('class_id', $this->class->id)->inClassOrder()->pluck('id')->all(),
        );

        $this->assertSame([$ids[1], $ids[2], $ids[0]], $ordered);
    }

    #[Test]
    public function students_without_a_number_go_last_and_ties_break_by_id(): void
    {
        // Created: 2, null, 2, 1, null, 2 — deliberately repeated and missing.
        $ids = $this->enrol([2, null, 2, 1, null, 2]);

        $ordered = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => Enrollment::query()->where('class_id', $this->class->id)->inClassOrder()->pluck('id')->all(),
        );

        $this->assertSame(
            [$ids[3], $ids[0], $ids[2], $ids[5], $ids[1], $ids[4]],
            $ordered,
            'Número 1, depois os três 2 por id, e por fim os dois sem número por id.',
        );
    }

    #[Test]
    public function the_cohort_resolver_uses_the_same_order(): void
    {
        $ids = $this->enrol([null, 5, 5, 1]);

        $ordered = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => ClassCohort::enrollmentsFor($this->class)->keys()->map(fn ($id): int => (int) $id)->all(),
        );

        $this->assertSame([$ids[3], $ids[1], $ids[2], $ids[0]], $ordered);
    }

    #[Test]
    public function the_order_also_works_through_a_relation(): void
    {
        $ids = $this->enrol([null, 2, 2, 1]);

        $ordered = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => $this->class->enrollments()->inClassOrder()->pluck('id')->all(),
        );

        $this->assertSame([$ids[3], $ids[1], $ids[2], $ids[0]], $ordered);
    }
}
