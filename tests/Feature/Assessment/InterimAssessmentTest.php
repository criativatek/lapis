<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Domain;
use App\Models\InterimAssessment;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Support\Assessment\AssessmentCutoff;
use App\Support\Assessment\InterimAssessmentException;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A photograph that keeps showing what was true when it was taken.
 *
 * The whole value of an interim assessment is that it does NOT move. A teacher
 * who corrects a score in January must still be able to open November's
 * assessment and see November — otherwise every comparison ever drawn from it,
 * and every grid ever exported from it, quietly changes meaning behind their
 * back.
 *
 * So most of what is defended here is absence of change: after the live class
 * has moved on, the snapshot has not.
 */
class InterimAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        // The demo year runs 2026/2027, which has not started in real time.
        // Standing at the end of it makes every date these tests use a date
        // that has actually happened — which is what the guard against future
        // photographs requires, and which the guard's own test undoes.
        $this->travelTo(Carbon::parse('2027-06-30 12:00:00'));
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(int $sequence): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    /**
     * The demo's first period runs 14/09/2026 → 29/01/2027 and its only
     * instrument was applied on 15/10/2026.
     */
    private function capture(string $date = '2026-11-15', int $sequence = 1, array $attributes = []): InterimAssessment
    {
        return $this->asTenant(fn (): InterimAssessment => app(CaptureInterimAssessment::class)->capture(
            $this->schoolClass(),
            $this->period($sequence),
            Carbon::parse($date),
            $this->teacher,
            $attributes,
        ));
    }

    // -------------------------------------------------- 1. tirar a fotografia

    #[Test]
    public function capturing_keeps_the_state_the_screen_was_showing(): void
    {
        $interim = $this->capture('2026-11-15');

        $live = $this->asTenant(fn (): array => app(BuildClassStatistics::class)->for(
            $this->schoolClass(),
            $this->period(1),
            AssessmentCutoff::on('2026-11-15'),
        ));

        // The same numbers, not a second opinion about them.
        $this->assertSame($live['summary'], $interim->snapshot['summary']);
        $this->assertSame($live['distribution'], $interim->snapshot['distribution']);
        $this->assertSame($live['domain_statistics'], $interim->snapshot['domain_statistics']);
        $this->assertCount(count($live['students']), $interim->snapshot['students']);
    }

    #[Test]
    public function it_is_stamped_with_a_version_and_a_hash(): void
    {
        $interim = $this->capture();

        $this->assertSame(InterimAssessment::CURRENT_VERSION, $interim->snapshot_version);
        $this->assertSame(1, $interim->snapshot['version']);
        $this->assertTrue($interim->isIntact());
        $this->assertSame(64, strlen($interim->snapshot_hash));
    }

    #[Test]
    public function the_name_is_suggested_but_the_teachers_own_wins(): void
    {
        $suggested = $this->capture('2026-11-15');
        $this->assertStringContainsString('Avaliação intercalar', $suggested->name);
        $this->assertStringContainsString('1.º Semestre', $suggested->name);

        $chosen = $this->capture('2026-11-16', attributes: ['name' => 'Antes das férias']);
        $this->assertSame('Antes das férias', $chosen->name);
    }

    #[Test]
    public function the_suggestion_counts_what_is_already_there(): void
    {
        $suggest = fn (): string => $this->asTenant(fn (): string => app(CaptureInterimAssessment::class)
            ->suggestedName($this->schoolClass(), $this->period(1)));

        $this->assertSame('Avaliação intercalar — 1.º Semestre', $suggest());

        $this->capture('2026-10-31');
        $this->assertSame('2.ª Avaliação intercalar — 1.º Semestre', $suggest());

        $this->capture('2026-11-15');
        $this->assertSame('3.ª Avaliação intercalar — 1.º Semestre', $suggest());

        // Counted among SIBLINGS, so the other period starts from one again.
        $this->assertSame(
            'Avaliação intercalar — 2.º Semestre',
            $this->asTenant(fn (): string => app(CaptureInterimAssessment::class)
                ->suggestedName($this->schoolClass(), $this->period(2))),
        );
    }

    #[Test]
    public function the_form_is_offered_the_suggestion_rather_than_having_it_applied(): void
    {
        $this->capture('2026-10-31');
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results/estatistica/{$this->period(1)->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('suggestedInterimName', '2.ª Avaliação intercalar — 1.º Semestre'));
    }

    #[Test]
    public function a_name_of_nothing_but_spaces_is_refused(): void
    {
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->post("/classes/{$class->ulid}/avaliacoes-intercalares", [
                'academic_period_id' => $this->period(1)->id,
                'reference_date' => '2026-11-15',
                'name' => '   ',
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, $this->asTenant(fn (): int => InterimAssessment::query()->count()));
    }

    #[Test]
    public function surrounding_and_repeated_spaces_are_tidied_and_accents_survive(): void
    {
        $interim = $this->capture('2026-11-15', attributes: [
            'name' => "  Avaliação   intercalar — Conselho de Turma \n",
        ]);

        $this->assertSame('Avaliação intercalar — Conselho de Turma', $interim->name);
    }

    #[Test]
    public function two_moments_with_the_same_name_are_still_two_different_moments(): void
    {
        // Nothing refuses this: identity is the ULID, and what a teacher calls
        // two moments is their business (§6).
        $first = $this->capture('2026-10-01', attributes: ['name' => 'Ponto de situação']);
        $second = $this->capture('2026-11-15', attributes: ['name' => 'Ponto de situação']);

        $this->assertNotSame($first->ulid, $second->ulid);
        $this->assertSame('Ponto de situação', $first->name);
        $this->assertSame('Ponto de situação', $second->name);

        // And they hold different states — the name never touched the content.
        $this->assertNull($first->snapshot['summary']['class_average']);
        $this->assertNotNull($second->snapshot['summary']['class_average']);
    }

    #[Test]
    public function the_name_chosen_does_not_change_a_single_number(): void
    {
        $plain = $this->capture('2026-11-15', attributes: ['name' => 'A']);
        $fancy = $this->capture('2026-11-15', attributes: ['name' => 'Um nome completamente diferente']);

        // Same date, same everything — a label on the envelope changes nothing
        // inside it (§4).
        $this->assertSame($plain->snapshot['summary'], $fancy->snapshot['summary']);
        $this->assertSame($plain->snapshot['students'], $fancy->snapshot['students']);
        $this->assertSame($plain->snapshot_hash, $fancy->snapshot_hash);
    }

    #[Test]
    public function renaming_afterwards_leaves_the_photograph_untouched(): void
    {
        $interim = $this->capture('2026-11-15', attributes: ['name' => 'Nome inicial']);
        $before = $interim->snapshot;
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->put("/classes/{$class->ulid}/avaliacoes-intercalares/{$interim->ulid}", [
                'name' => '  Avaliação   intercalar de novembro  ',
                'note' => 'Corrigido o nome.',
            ])
            ->assertRedirect();

        $again = $this->asTenant(fn (): InterimAssessment => InterimAssessment::findOrFail($interim->id));

        $this->assertSame('Avaliação intercalar de novembro', $again->name);
        $this->assertSame('Corrigido o nome.', $again->note);
        // THE LINE THAT MATTERS: the content, its date and its hash did not move.
        $this->assertSame($before, $again->snapshot);
        $this->assertSame($interim->snapshot_hash, $again->snapshot_hash);
        $this->assertSame('2026-11-15', $again->reference_date->toDateString());
        $this->assertTrue($again->isIntact());
    }

    #[Test]
    public function the_content_still_refuses_to_be_written_over(): void
    {
        $interim = $this->capture();

        // Renaming is allowed; rewriting history is not, and the model says so
        // by naming the frozen attribute.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('reference_date');

        $interim->update(['reference_date' => '2026-12-01']);
    }

    // ------------------------------------------------------ 2. imutabilidade

    #[Test]
    public function correcting_a_score_afterwards_does_not_move_the_photograph(): void
    {
        $interim = $this->capture('2026-11-15');
        $before = $interim->snapshot;

        // January: the teacher corrects a mark.
        $this->asTenant(function (): void {
            StudentItemScore::query()
                ->where('result_state', 'assessed')
                ->orderBy('id')
                ->first()
                ?->update(['points_earned' => 1]);
        });

        $again = $this->asTenant(fn (): InterimAssessment => InterimAssessment::findOrFail($interim->id));

        $this->assertSame($before, $again->snapshot, 'a fotografia não pode mexer-se');
        $this->assertTrue($again->isIntact());

        // And the live reading HAS moved — otherwise this proves nothing.
        $live = $this->asTenant(fn (): array => app(BuildClassStatistics::class)->for(
            $this->schoolClass(),
            $this->period(1),
            AssessmentCutoff::on('2026-11-15'),
        ));

        $this->assertNotSame($before['summary']['class_average'], $live['summary']['class_average']);
    }

    #[Test]
    public function the_snapshot_itself_refuses_to_be_written_over(): void
    {
        $interim = $this->capture();

        // Not merely discouraged: correcting the CONTENT means creating a new
        // one beside it, because everything already drawn from this one depends
        // on it not moving. The name is a label on the envelope and has its own
        // test; this is what is inside.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('snapshot');

        $interim->update(['snapshot' => ['version' => 1, 'tampered' => true]]);
    }

    #[Test]
    public function a_renamed_domain_does_not_destroy_the_history(): void
    {
        $interim = $this->capture('2026-11-15');
        $labels = array_column($interim->snapshot['domains'], 'label_snapshot');

        $this->assertContains('Leitura', $labels);

        $this->asTenant(fn () => Domain::where('code', 'LEITURA')->firstOrFail()->update(['name' => 'Compreensão Leitora']));

        $again = $this->asTenant(fn (): InterimAssessment => InterimAssessment::findOrFail($interim->id));

        // The words that were on screen travel with the photograph; the ids
        // travel too, so a later export still matches something real (§15).
        $this->assertContains('Leitura', array_column($again->snapshot['domains'], 'label_snapshot'));
        $this->assertNotContains(null, array_column($again->snapshot['domains'], 'domain_id'));
    }

    #[Test]
    public function a_reconfigured_scale_does_not_destroy_the_history(): void
    {
        $interim = $this->capture('2026-11-15');
        $bands = $interim->snapshot['scale']['bands'];

        $this->assertContains('Bom', array_column($bands, 'label_snapshot'));
        $this->assertContains('B', array_column($bands, 'inovar_code_snapshot'));

        $this->asTenant(function (): void {
            Scale::where('name', 'Escala 1 a 5')->firstOrFail()
                ->levels()->where('code', '4')
                ->update(['label' => 'Desempenho Bom', 'inovar_code' => 'XX']);
        });

        $again = $this->asTenant(fn (): InterimAssessment => InterimAssessment::findOrFail($interim->id));
        $stored = $again->snapshot['scale']['bands'];

        // Both the words AND the INOVAR code are historical: a grid produced
        // from this photograph in January must carry November's code (§34).
        $this->assertContains('Bom', array_column($stored, 'label_snapshot'));
        $this->assertContains('B', array_column($stored, 'inovar_code_snapshot'));
        $this->assertNotContains('XX', array_column($stored, 'inovar_code_snapshot'));
    }

    // -------------------------------------------------- 3. o que fica guardado

    #[Test]
    public function each_student_keeps_their_reading_and_the_reason_behind_any_warning(): void
    {
        $interim = $this->capture('2026-11-15');
        $students = $interim->snapshot['students'];

        $this->assertNotEmpty($students);

        foreach ($students as $student) {
            $this->assertArrayHasKey('enrollment_id', $student);
            $this->assertArrayHasKey('name_snapshot', $student);
            $this->assertArrayHasKey('weighted_average', $student);
            $this->assertArrayHasKey('accumulated_average', $student);
            $this->assertArrayHasKey('band', $student);
            $this->assertArrayHasKey('self_assessment', $student);
            $this->assertArrayHasKey('classification', $student);

            foreach ($student['domains'] as $cell) {
                $this->assertArrayHasKey('label_snapshot', $cell);
                $this->assertArrayHasKey('mention', $cell);
                $this->assertArrayHasKey('coverage_elements', $cell);
            }
        }
    }

    #[Test]
    public function a_band_is_kept_by_its_identity_and_not_only_by_its_words(): void
    {
        $interim = $this->capture('2026-11-15');

        $withBand = array_values(array_filter(
            $interim->snapshot['students'],
            fn (array $student): bool => $student['band'] !== null,
        ));

        $this->assertNotEmpty($withBand);

        foreach ($withBand as $student) {
            // «Bom» alone could never be mapped back to anything (§16).
            $this->assertNotNull($student['band']['scale_level_id']);
            $this->assertNotNull($student['band']['code_snapshot']);
            $this->assertNotNull($student['band']['label_snapshot']);
        }
    }

    // ------------------------------------------------------------ 4. a data

    #[Test]
    public function the_date_must_fall_inside_the_period_it_belongs_to(): void
    {
        // The first semester runs 14/09/2026 → 29/01/2027.
        $this->expectException(InterimAssessmentException::class);
        $this->capture('2026-09-01');
    }

    #[Test]
    public function a_date_after_the_period_ends_is_refused(): void
    {
        $this->expectException(InterimAssessmentException::class);
        $this->capture('2027-02-15', sequence: 1);
    }

    #[Test]
    public function the_boundaries_of_the_period_are_read_from_its_own_dates(): void
    {
        $period = $this->period(1);

        // Its own starts_on/ends_on, never inferred from the words «1.º
        // Semestre» — a school that names its periods differently is validated
        // exactly the same way (§6).
        $first = $this->capture($period->starts_on->toDateString());
        $this->assertNotNull($first->id);

        $this->assertSame(
            $period->starts_on->toDateString(),
            $first->reference_date->toDateString(),
        );
    }

    #[Test]
    public function a_date_that_has_not_arrived_yet_is_refused(): void
    {
        // Mid-November, looking at the end of January. A photograph of a moment
        // that has not happened would be a photograph of today wearing the
        // wrong date — which is worse than none at all.
        $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));

        $this->expectException(InterimAssessmentException::class);
        $this->expectExceptionMessage('data futura');

        $this->capture('2027-01-20');
    }

    #[Test]
    public function a_period_from_another_year_is_refused(): void
    {
        $stranger = User::factory()->create();

        $this->expectException(InterimAssessmentException::class);

        $this->asTenant(function () use ($stranger): void {
            $otherYear = AcademicYear::create([
                'label' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-06-30',
                'status' => 'active', 'country_code' => 'PT',
            ]);
            $foreign = $otherYear->periods()->create([
                'label' => 'Único', 'kind' => 'semester', 'sequence' => 1,
                'starts_on' => '2030-09-01', 'ends_on' => '2031-06-30', 'status' => 'open',
            ]);

            app(CaptureInterimAssessment::class)->capture(
                $this->schoolClass(),
                $foreign,
                Carbon::parse('2030-10-01'),
                $stranger,
            );
        });
    }

    // ------------------------------------------------ 5. várias intercalares

    #[Test]
    public function a_class_can_have_none_one_or_several(): void
    {
        $this->assertSame(0, $this->asTenant(fn (): int => InterimAssessment::query()->count()));

        $this->capture('2026-10-31');
        $this->capture('2026-12-12');
        $this->capture('2027-03-10', sequence: 2);

        $all = $this->asTenant(fn () => InterimAssessment::query()->orderBy('reference_date')->get());

        // Nothing anywhere says «one per period», and nothing hardcodes «the
        // middle of the semester» (§20).
        $this->assertCount(3, $all);
        $this->assertSame(
            ['2026-10-31', '2026-12-12', '2027-03-10'],
            $all->map(fn ($row): string => $row->reference_date->toDateString())->all(),
        );

        $firstPeriod = $this->period(1)->id;
        $this->assertSame(2, $all->where('academic_period_id', $firstPeriod)->count());
    }

    #[Test]
    public function two_interims_on_different_dates_hold_different_states(): void
    {
        // Before the only instrument of the period, and after it.
        $early = $this->capture('2026-10-01');
        $late = $this->capture('2026-11-15');

        $this->assertNull($early->snapshot['summary']['class_average']);
        $this->assertNotNull($late->snapshot['summary']['class_average']);
    }

    // ------------------------------------------------------ 6. isolamento

    // ------------------------------------------------------ 7. pela web

    #[Test]
    public function a_teacher_can_keep_a_moment_and_open_it_again(): void
    {
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->post("/classes/{$class->ulid}/avaliacoes-intercalares", [
                'academic_period_id' => $this->period(1)->id,
                'reference_date' => '2026-11-15',
                'name' => 'Antes do Natal',
                'note' => 'Ponto de situação.',
            ])
            ->assertRedirect();

        $interim = $this->asTenant(fn (): InterimAssessment => InterimAssessment::firstOrFail());

        $this->assertSame('Antes do Natal', $interim->name);

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/avaliacoes-intercalares/{$interim->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('results/InterimAssessment')
                ->where('interim.reference_date_label', '15/11/2026')
                ->where('interim.is_intact', true)
                ->has('snapshot.students')
                ->has('snapshot.summary'));
    }

    #[Test]
    public function a_refused_date_comes_back_saying_which_boundary_it_broke(): void
    {
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->post("/classes/{$class->ulid}/avaliacoes-intercalares", [
                'academic_period_id' => $this->period(1)->id,
                'reference_date' => '2026-09-01',
                'name' => 'Cedo demais',
            ])
            ->assertSessionHasErrors('reference_date');

        // Nothing was written on the way out.
        $this->assertSame(0, $this->asTenant(fn (): int => InterimAssessment::query()->count()));
    }

    #[Test]
    public function the_statistics_page_lists_what_has_been_kept(): void
    {
        $this->capture('2026-10-31', attributes: ['name' => 'Primeira']);
        $this->capture('2026-12-12', attributes: ['name' => 'Segunda']);

        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/results/estatistica")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('interimAssessments', 2)
                // Oldest first: a class's own timeline of moments (§28).
                ->where('interimAssessments.0.name', 'Primeira')
                ->where('interimAssessments.1.name', 'Segunda'));
    }

    #[Test]
    public function another_organization_cannot_open_a_kept_moment(): void
    {
        $interim = $this->capture();
        $class = $this->schoolClass();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get("/classes/{$class->ulid}/avaliacoes-intercalares/{$interim->ulid}")
            ->assertNotFound();
    }

    #[Test]
    public function a_moment_kept_for_another_class_is_not_reachable_through_this_one(): void
    {
        $interim = $this->capture();

        $otherClass = $this->asTenant(function (): SchoolClass {
            $class = $this->schoolClass();

            return SchoolClass::create([
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
                'label' => '7.º B',
                'grade_level' => '7.º',
                'status' => 'active',
            ]);
        });

        // Same organization, wrong class: the ULID is not a key to everything.
        //
        // The refusal comes from the class policy, which stops a teacher at a
        // class they do not teach before the interim is even looked at — so the
        // answer is 403 rather than 404. The controller's own check that the
        // photograph belongs to this class is the second lock, for a teacher
        // who does teach both.
        $this->actingAs($this->teacher)
            ->get("/classes/{$otherClass->ulid}/avaliacoes-intercalares/{$interim->ulid}")
            ->assertForbidden();
    }

    #[Test]
    public function a_forged_identifier_finds_nothing(): void
    {
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/avaliacoes-intercalares/01ZZZZZZZZZZZZZZZZZZZZZZZZ")
            ->assertNotFound();
    }

    #[Test]
    public function an_interim_belongs_to_the_organization_that_took_it(): void
    {
        $interim = $this->capture();

        $this->assertSame(
            $this->teacher->personalOrganization()->id,
            $interim->organization_id,
        );

        // Another organization sees nothing at all.
        $stranger = User::factory()->create();

        $count = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            fn (): int => InterimAssessment::query()->count(),
        );

        $this->assertSame(0, $count);
    }
}
