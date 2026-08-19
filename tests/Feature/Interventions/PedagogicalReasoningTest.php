<?php

namespace Tests\Feature\Interventions;

use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Intervention;
use App\Models\InterventionEffectiveness;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\Organization;
use App\Models\ReportLibraryEntry;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Database\Seeders\ReportLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The reasoning an intervention now records (§1, §62–§75).
 *
 * THE FAILURE THIS MODULE MUST NOT HAVE is the system deciding something a
 * teacher did not. A difficulty inferred from a low result, an effect inferred
 * from a figure that rose, a strategy suggested because it was next in a list:
 * each would read like documentation of a professional judgement and be nothing
 * of the kind.
 *
 * So the tests that matter here are the ones asserting ABSENCE — that a student
 * with poor results produces no difficulty, that an intervention followed by a
 * rise stays unrated, and that an old row with nothing filled in shows nothing
 * rather than a plausible default.
 */
class PedagogicalReasoningTest extends TestCase
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

        $this->travelTo(Carbon::parse('2027-03-15 10:00:00'));

        $this->seed(EntitlementsSeeder::class);
    }

    // ------------------------------------------------------------- andaimes

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

    private function enrollment(int $position = 1): Enrollment
    {
        return $this->asTenant(fn (): Enrollment => Enrollment::query()
            ->where('class_id', $this->schoolClass()->getKey())
            ->orderBy('class_number')
            ->skip($position - 1)
            ->firstOrFail());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'target_type' => InterventionTargetType::Student->value,
            'enrollment_ids' => [$this->enrollment()->getKey()],
            'intervention_type' => InterventionType::WritingOrganizationSupport->value,
            'domain_relation' => 'none',
            'started_on' => '2027-02-15',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(array $overrides = []): Intervention
    {
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/interventions")
            ->post("/classes/{$class->ulid}/interventions", $this->payload($overrides))
            ->assertRedirect();

        return $this->asTenant(fn (): Intervention => Intervention::query()->latest('id')->firstOrFail());
    }

    // -------------------------------------------------------- §62 criação

    #[Test]
    public function an_intervention_can_be_registered_with_nothing_but_the_essentials(): void
    {
        $intervention = $this->create();

        // THE FAST PATH STILL WORKS. Nothing about the reasoning is required,
        // and an absence stays an absence rather than becoming a default (§4,
        // §16).
        $this->assertNull($intervention->motive_label);
        $this->assertNull($intervention->objective);
        $this->assertNull($intervention->review_on);
        $this->assertSame(InterventionStatus::New, $intervention->status);
    }

    #[Test]
    public function the_teacher_can_write_their_own_situation_strategy_and_objective(): void
    {
        $intervention = $this->create([
            'motive_label' => 'Dificuldade na planificação da escrita',
            'strategy_label' => 'Escrita orientada com guião de planificação',
            'objective' => 'Melhorar a organização e a estruturação do texto escrito.',
            'review_on' => '2027-04-20',
        ]);

        $this->assertSame('Dificuldade na planificação da escrita', $intervention->motive_label);
        $this->assertSame('Escrita orientada com guião de planificação', $intervention->strategy_label);
        $this->assertSame('2027-04-20', $intervention->review_on->toDateString());

        // No code, because no library entry was named. The words are the whole
        // of what was chosen (§53).
        $this->assertNull($intervention->motive_code);
        $this->assertNull($intervention->strategy_code);

        // The strategy is what a screen calls it — not the catalogue type (§11).
        $this->assertSame('Escrita orientada com guião de planificação', $intervention->displayTitle());
    }

    #[Test]
    public function a_library_entry_is_copied_rather_than_referenced(): void
    {
        $this->seed(ReportLibrarySeeder::class);

        $difficulty = $this->asTenant(fn (): ReportLibraryEntry => ReportLibraryEntry::query()
            ->ofKind(ReportLibraryEntry::KIND_DIFFICULTY)->orderBy('id')->firstOrFail());

        $intervention = $this->create([
            'motive_code' => $difficulty->code,
            // A label the client sent that disagrees with the library: the
            // library wins, because the code is the claim about provenance.
            'motive_label' => 'qualquer coisa que o cliente enviou',
        ]);

        $this->assertSame($difficulty->code, $intervention->motive_code);
        $this->assertSame($difficulty->label, $intervention->motive_label);

        // THE SNAPSHOT IS THE POINT. Rewording the library must not rewrite an
        // intervention recorded before the change (§56).
        $wording = (string) $difficulty->label;
        $this->asTenant(fn () => $difficulty->forceFill(['label' => 'Uma formulação totalmente diferente'])->save());

        $this->assertSame($wording, $this->asTenant(fn () => $intervention->fresh()->motive_label));
    }

    #[Test]
    public function a_code_that_names_nothing_is_dropped_rather_than_stored(): void
    {
        $intervention = $this->create([
            'motive_code' => 'uma-coisa-que-nao-existe',
            'motive_label' => 'A minha própria formulação',
        ]);

        // Storing an unresolvable pointer would be storing a claim about an
        // entry nobody can read.
        $this->assertNull($intervention->motive_code);
        $this->assertSame('A minha própria formulação', $intervention->motive_label);
    }

    // ---------------------------------------------------- §64 não inferir

    #[Test]
    public function a_student_with_poor_results_is_offered_no_difficulty_of_their_own(): void
    {
        $class = $this->schoolClass();

        $page = $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/interventions")
            ->assertOk();

        $library = $page->viewData('page')['props']['library'];

        // The library is a REFERENCE LIST, identical for every student in the
        // class. Nothing in this payload is about anybody's results, and there
        // is no field that could carry a difficulty the system decided (§2, §9).
        $this->assertIsArray($library['difficulties']);
        $this->assertArrayNotHasKey('suggested', $library);

        foreach ($page->viewData('page')['props']['interventions'] as $row) {
            $this->assertArrayHasKey('motive', $row);
            $this->assertNull($row['motive']);
        }
    }

    #[Test]
    public function strategies_are_offered_only_for_the_difficulty_they_answer(): void
    {
        $this->seed(ReportLibrarySeeder::class);

        $class = $this->schoolClass();

        $grouped = $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/interventions")
            ->viewData('page')['props']['library']['strategies'];

        $this->assertNotEmpty($grouped);

        // Each strategy sits under the code it declares it answers, and under
        // no other. A flat list under every difficulty is what makes a module
        // read like a form letter (§13, §65).
        foreach ($grouped as $difficultyCode => $strategies) {
            foreach ($strategies as $strategy) {
                $this->assertSame($difficultyCode, $strategy['related_code']);
            }
        }
    }

    // ------------------------------------------------------ §67 follow-ups

    #[Test]
    public function follow_ups_accumulate_and_never_replace_one_another(): void
    {
        $intervention = $this->create(['motive_label' => 'Planificação da escrita']);

        foreach ([
            ['2027-03-02', null, 'Maior autonomia na planificação do texto.'],
            ['2027-03-20', InterventionEffectiveness::PartiallyEffective->value, 'Continua a necessitar de apoio na revisão.'],
        ] as [$date, $rating, $note]) {
            $this->actingAs($this->teacher)
                ->from('/interventions')
                ->post("/interventions/{$intervention->ulid}/reviews", [
                    'reviewed_on' => $date,
                    'effectiveness' => $rating,
                    'notes' => $note,
                ])
                ->assertRedirect();
        }

        $fresh = $this->asTenant(fn (): Intervention => $intervention->fresh()->load('reviews'));

        $this->assertCount(2, $fresh->reviews);
        // Newest first, and both survive: the March note is not overwritten by
        // the one in April (§28).
        $this->assertSame('2027-03-20', $fresh->reviews[0]->reviewed_on->toDateString());
        $this->assertSame('Maior autonomia na planificação do texto.', $fresh->reviews[1]->notes);

        // The intervention's own description is untouched by any of it (§23).
        $this->assertNull($fresh->description);
        $this->assertSame('Planificação da escrita', $fresh->motive_label);
    }

    #[Test]
    public function the_current_appraisal_is_the_latest_one_that_made_a_judgement(): void
    {
        $intervention = $this->create();

        $this->addFollowUp($intervention, '2027-03-02', InterventionEffectiveness::NotEffective->value);
        // A later follow-up that only OBSERVED. It must not erase the appraisal
        // that stands — «não julguei desta vez» is not «já não se aplica».
        $this->addFollowUp($intervention, '2027-03-20', null, 'Sem alterações a assinalar.');

        $this->assertSame(
            InterventionEffectiveness::NotEffective,
            $this->asTenant(fn () => $intervention->fresh()->load('reviews')->currentEffectiveness()),
        );
    }

    // -------------------------------------------------------- §68 o efeito

    #[Test]
    public function a_result_that_rises_after_an_intervention_rates_nothing(): void
    {
        $intervention = $this->create();

        // Whatever happens to the numbers afterwards, and however suggestive:
        // every score in the class moves.
        $this->asTenant(fn () => DB::table('student_item_scores')->delete());

        $fresh = $this->asTenant(fn (): Intervention => $intervention->fresh()->load('reviews'));

        // ONLY THE TEACHER MAY SAY IT. There is no path in this application
        // that writes an appraisal, and the absence of one is the honest state
        // (§26, §68).
        $this->assertNull($fresh->currentEffectiveness());
        $this->assertCount(0, $fresh->reviews);
    }

    // ------------------------------------------------------ §69 review_on

    #[Test]
    public function a_review_date_in_the_past_makes_an_open_intervention_pending(): void
    {
        $intervention = $this->create(['review_on' => '2027-03-14']);

        $this->assertTrue($this->asTenant(fn () => $intervention->fresh()->needsReview()));
    }

    #[Test]
    public function a_review_date_in_the_future_is_not_pending(): void
    {
        $intervention = $this->create(['review_on' => '2027-03-16']);

        $this->assertFalse($this->asTenant(fn () => $intervention->fresh()->needsReview()));
    }

    #[Test]
    public function an_intervention_with_no_review_date_is_never_pending(): void
    {
        // NO RULE INVENTS ONE. An intervention running quietly since October is
        // not neglected, and no elapsed-time threshold exists anywhere in this
        // module to say otherwise (§22, §37).
        $intervention = $this->create(['started_on' => '2026-10-01']);

        $this->assertFalse($this->asTenant(fn () => $intervention->fresh()->needsReview()));
    }

    #[Test]
    public function a_concluded_intervention_stops_being_pending(): void
    {
        $intervention = $this->create(['review_on' => '2027-03-01']);

        $this->assertTrue($this->asTenant(fn () => $intervention->fresh()->needsReview()));

        $this->conclude($intervention);

        $fresh = $this->asTenant(fn () => $intervention->fresh());

        $this->assertFalse($fresh->needsReview());
        $this->assertNull($fresh->review_on);
    }

    #[Test]
    public function the_pending_filter_lists_only_what_the_teacher_said_to_revisit(): void
    {
        $class = $this->schoolClass();

        $pending = $this->create(['review_on' => '2027-03-01']);
        $this->create(['review_on' => '2027-12-01']);
        $this->create();

        $rows = $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/interventions?needs_review=1")
            ->viewData('page')['props']['interventions'];

        $this->assertCount(1, $rows);
        $this->assertSame($pending->ulid, $rows[0]['ulid']);
    }

    // ---------------------------------------------------------- §70 estados

    #[Test]
    public function an_intervention_moves_through_its_states_and_can_be_reopened(): void
    {
        $intervention = $this->create();

        $this->setStatus($intervention, InterventionStatus::InProgress->value);
        $this->assertSame(InterventionStatus::InProgress, $this->asTenant(fn () => $intervention->fresh()->status));

        $this->setStatus($intervention, InterventionStatus::Suspended->value);
        $this->assertSame(InterventionStatus::Suspended, $this->asTenant(fn () => $intervention->fresh()->status));

        $this->conclude($intervention);
        $this->assertNotNull($this->asTenant(fn () => $intervention->fresh()->concluded_on));

        $this->setStatus($intervention, InterventionStatus::InProgress->value);

        $reopened = $this->asTenant(fn () => $intervention->fresh());

        // Reopening clears the end date, because it no longer ended — and the
        // trail keeps both events (§44).
        $this->assertNull($reopened->concluded_on);
        $this->assertNotNull(
            $this->asTenant(fn () => AuditEvent::where('event', 'intervention.reopened')->first()),
        );
    }

    #[Test]
    public function concluding_may_record_what_was_observed_and_may_not(): void
    {
        $withNothing = $this->create();
        $this->conclude($withNothing);

        // «Concluída sem avaliação registada» is a real answer (§43).
        $this->assertNull($this->asTenant(fn () => $withNothing->fresh()->load('reviews')->currentEffectiveness()));

        $withAppraisal = $this->create();
        $this->conclude($withAppraisal, InterventionEffectiveness::Effective->value, 'Passou a planificar de forma autónoma.');

        $fresh = $this->asTenant(fn () => $withAppraisal->fresh()->load('reviews'));

        $this->assertSame(InterventionEffectiveness::Effective, $fresh->currentEffectiveness());
        // Recorded as a follow-up, dated, and part of the history like any
        // other — not a column on the intervention (§28, §59).
        $this->assertCount(1, $fresh->reviews);
    }

    // ---------------------------------------------------------- §75 legacy

    #[Test]
    public function an_intervention_with_nothing_filled_in_shows_no_technical_labels(): void
    {
        $class = $this->schoolClass();

        // The shape of a row recorded before any of this existed.
        $legacy = $this->asTenant(fn (): Intervention => Intervention::create([
            'class_id' => $class->getKey(),
            'enrollment_id' => $this->enrollment()->getKey(),
            'target_type' => InterventionTargetType::Student,
            'intervention_type' => null,
            'title' => 'Acompanhamento combinado com a diretora de turma',
            'status' => InterventionStatus::Concluded,
            'started_on' => '2026-11-10',
            'created_by' => $this->teacher->getKey(),
        ]));

        $this->asTenant(fn () => $legacy->participants()->sync([$this->enrollment()->getKey()]));

        $row = collect($this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/interventions")
            ->viewData('page')['props']['interventions'])
            ->firstWhere('ulid', $legacy->ulid);

        $this->assertNotNull($row);
        $this->assertSame('Acompanhamento combinado com a diretora de turma', $row['title']);

        // Absences, shown as absences. Never «legado», «null», «sem domínio» or
        // a code (§35).
        foreach (['motive', 'objective', 'strategy', 'intervention_type_label', 'effectiveness'] as $key) {
            $this->assertNull($row[$key]);
        }

        $encoded = (string) json_encode($row, JSON_UNESCAPED_UNICODE);

        foreach (['legacy', 'Legado', 'unknown', 'Sem domínio'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    // ------------------------------------------------------- §17 pré-seleção

    #[Test]
    public function arriving_from_a_students_page_opens_the_form_on_them(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $prefill = $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/interventions?aluno={$enrollment->ulid}")
            ->viewData('page')['props']['prefill'];

        $this->assertSame('student', $prefill['target_type']);
        $this->assertSame([$enrollment->getKey()], $prefill['enrollment_ids']);
    }

    #[Test]
    public function a_student_who_has_left_does_not_prefill_a_new_intervention(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->asTenant(fn () => $enrollment->update(['status' => EnrollmentStatus::TransferredOut]));

        // The page still opens — on nobody in particular. A link is a
        // suggestion, not an instruction (§19, §20).
        $this->assertNull(
            $this->actingAs($this->teacher)
                ->get("/classes/{$class->ulid}/interventions?aluno={$enrollment->ulid}")
                ->assertOk()
                ->viewData('page')['props']['prefill'],
        );
    }

    #[Test]
    public function an_enrolment_from_another_class_never_prefills(): void
    {
        $class = $this->schoolClass();

        $stranger = $this->asTenant(function () use ($class): Enrollment {
            $other = SchoolClass::factory()->create([
                'organization_id' => $this->organization->getKey(),
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
                'label' => '7.º B',
            ]);

            return Enrollment::factory()->create([
                'organization_id' => $this->organization->getKey(),
                'class_id' => $other->getKey(),
            ]);
        });

        $this->assertNull(
            $this->actingAs($this->teacher)
                ->get("/classes/{$class->ulid}/interventions?aluno={$stranger->ulid}")
                ->viewData('page')['props']['prefill'],
        );
    }

    // ------------------------------------------------------------- §83 audit

    #[Test]
    public function the_trail_records_what_happened_without_copying_the_words(): void
    {
        $intervention = $this->create(['motive_label' => 'Planificação da escrita']);

        $this->addFollowUp(
            $intervention,
            '2027-03-02',
            InterventionEffectiveness::PartiallyEffective->value,
            'O aluno referiu dificuldades em casa que não devem sair daqui.',
        );

        $event = $this->asTenant(fn (): ?AuditEvent => AuditEvent::query()
            ->where('event', 'intervention.followup_added')->latest('id')->first());

        $this->assertNotNull($event);
        $this->assertSame('partially_effective', $event->properties['effectiveness']);
        $this->assertTrue($event->properties['has_note']);

        // THE NOTE ITSELF NEVER TRAVELS. A follow-up may say something delicate
        // about a child, and copying it into a second table would double the
        // number of places it has to be protected for no gain (§84).
        $this->assertStringNotContainsString(
            'dificuldades em casa',
            (string) json_encode($event->properties, JSON_UNESCAPED_UNICODE),
        );
    }

    // ------------------------------------------------------ §78 performance

    #[Test]
    public function the_list_does_not_query_once_per_intervention(): void
    {
        $class = $this->schoolClass();

        foreach (range(1, 12) as $index) {
            $this->create(['started_on' => '2027-02-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT)]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}/interventions")->assertOk();

        // A ceiling, not a target. Participants, domains and follow-ups are
        // eager-loaded, so twelve interventions cost what two would; a query
        // per row would go straight through this (§78).
        $this->assertLessThan(40, $queries);
    }

    // -------------------------------------------------------------- suporte

    private function addFollowUp(Intervention $intervention, string $date, ?string $rating, ?string $note = null): void
    {
        $this->actingAs($this->teacher)
            ->from('/interventions')
            ->post("/interventions/{$intervention->ulid}/reviews", [
                'reviewed_on' => $date,
                'effectiveness' => $rating,
                'notes' => $note,
            ])
            ->assertRedirect();
    }

    private function setStatus(Intervention $intervention, string $status): void
    {
        $this->actingAs($this->teacher)
            ->from('/interventions')
            ->patch("/interventions/{$intervention->ulid}", ['status' => $status])
            ->assertRedirect();
    }

    private function conclude(Intervention $intervention, ?string $rating = null, ?string $note = null): void
    {
        $this->actingAs($this->teacher)
            ->from('/interventions')
            ->patch("/interventions/{$intervention->ulid}", [
                'status' => InterventionStatus::Concluded->value,
                'effectiveness' => $rating,
                'notes' => $note,
            ])
            ->assertRedirect();
    }
}
