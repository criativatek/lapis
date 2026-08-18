<?php

namespace Tests\Feature\Reports;

use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may do what to a report (§63, §64).
 *
 * The asymmetry these pin down is deliberate: a colleague who teaches the class
 * may READ a report about it — they already see every grade in it — but may not
 * rewrite a document that carries somebody else's name. Deriving their own from
 * it is always open (§32).
 */
class ReportPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected User $author;

    protected User $colleague;

    protected User $stranger;

    protected Organization $organization;

    protected SchoolClass $class;

    protected Report $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create();
        $this->organization = $this->author->personalOrganization();

        $this->colleague = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$this->colleague->id => ['joined_at' => now()]]);

        $this->stranger = User::factory()->create();

        $this->asTenant(function (): void {
            $this->class = SchoolClass::factory()->recycle($this->organization)->create();
            $this->class->teachers()->syncWithoutDetaching([
                $this->author->id => ['role' => 'owner'],
                $this->colleague->id => ['role' => 'teacher'],
            ]);

            $this->report = Report::factory()->recycle($this->organization)->create([
                'class_id' => $this->class->id,
                'created_by' => $this->author->id,
            ]);
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback, ?Organization $organization = null): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization ?? $this->organization, $callback);
    }

    #[Test]
    public function the_author_may_read_write_finalize_and_delete_their_draft(): void
    {
        $this->asTenant(function (): void {
            $this->assertTrue(Gate::forUser($this->author)->allows('view', $this->report));
            $this->assertTrue(Gate::forUser($this->author)->allows('update', $this->report));
            $this->assertTrue(Gate::forUser($this->author)->allows('finalize', $this->report));
            $this->assertTrue(Gate::forUser($this->author)->allows('delete', $this->report));
            $this->assertTrue(Gate::forUser($this->author)->allows('export', $this->report));
        });
    }

    #[Test]
    public function a_teacher_of_the_class_may_read_but_not_rewrite(): void
    {
        $this->asTenant(function (): void {
            $this->assertTrue(Gate::forUser($this->colleague)->allows('view', $this->report));
            $this->assertTrue(Gate::forUser($this->colleague)->allows('export', $this->report));
            // Their own copy, always available — the honest way to disagree.
            $this->assertTrue(Gate::forUser($this->colleague)->allows('derive', $this->report));

            $this->assertFalse(Gate::forUser($this->colleague)->allows('update', $this->report));
            $this->assertFalse(Gate::forUser($this->colleague)->allows('finalize', $this->report));
            $this->assertFalse(Gate::forUser($this->colleague)->allows('delete', $this->report));
        });
    }

    #[Test]
    public function a_user_who_does_not_teach_the_class_may_not_even_read_it(): void
    {
        $outsider = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$outsider->id => ['joined_at' => now()]]);

        $this->asTenant(function () use ($outsider): void {
            $this->assertFalse(Gate::forUser($outsider)->allows('view', $this->report));
            $this->assertFalse(Gate::forUser($outsider)->allows('export', $this->report));
        });
    }

    #[Test]
    public function nobody_may_rewrite_or_delete_a_finalized_report_including_its_author(): void
    {
        $finalized = $this->asTenant(fn () => Report::factory()
            ->recycle($this->organization)
            ->finalized()
            ->create(['class_id' => $this->class->id, 'created_by' => $this->author->id]));

        $this->asTenant(function () use ($finalized): void {
            $this->assertTrue(Gate::forUser($this->author)->allows('view', $finalized));
            $this->assertTrue(Gate::forUser($this->author)->allows('export', $finalized));
            // A new draft from it: yes. Editing it: never (§37, §55).
            $this->assertTrue(Gate::forUser($this->author)->allows('derive', $finalized));

            $this->assertFalse(Gate::forUser($this->author)->allows('update', $finalized));
            $this->assertFalse(Gate::forUser($this->author)->allows('finalize', $finalized));
            $this->assertFalse(Gate::forUser($this->author)->allows('delete', $finalized));
        });
    }

    #[Test]
    public function the_school_report_belongs_to_whoever_owns_the_organization(): void
    {
        $schoolReport = $this->asTenant(fn () => Report::factory()
            ->recycle($this->organization)
            ->school()
            ->create([
                'created_by' => $this->author->id,
                'academic_year_id' => AcademicYear::factory()->recycle($this->organization)->create()->id,
            ]));

        $this->asTenant(function () use ($schoolReport): void {
            // The author owns this personal organization, so both routes agree.
            $this->assertTrue(Gate::forUser($this->author)->allows('view', $schoolReport));
            // A member who does not own it, and did not write it, does not see it.
            $this->assertFalse(Gate::forUser($this->colleague)->allows('view', $schoolReport));
        });
    }
}
