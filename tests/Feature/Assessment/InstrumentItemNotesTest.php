<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\ResultsAnalysisNote;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The teacher's private note on a question/criterion (instrument_items.criterion_note)
 * and the «Comentários» tab that edits it.
 */
class InstrumentItemNotesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->organization = $this->user->personalOrganization();
    }

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    /**
     * @return array{instrument: Instrument, class: SchoolClass, period: AcademicPeriod}
     */
    protected function scenarioWithClass(string $status = 'prepared'): array
    {
        $org = $this->organization;
        $year = AcademicYear::factory()->recycle($org)->create();
        $period = AcademicPeriod::factory()->recycle($org)->for($year)->create();
        $subject = Subject::factory()->recycle($org)->create();
        $class = SchoolClass::factory()->recycle($org)->create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
        ]);
        $class->teachers()->attach($this->user, ['role' => 'owner']);

        $instrument = app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $period->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Apresentação oral',
            'applied_on' => '2026-10-15',
            'status' => $status,
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
        ], [
            ['code' => 'Q1', 'points_possible' => 50],
            ['code' => 'Q2', 'points_possible' => 50, 'criterion_note' => 'Audibilidade 5 + dicção 10'],
        ]);

        return ['instrument' => $instrument, 'class' => $class, 'period' => $period];
    }

    protected function scenario(string $status = 'prepared'): Instrument
    {
        return $this->scenarioWithClass($status)['instrument'];
    }

    #[Test]
    public function it_saves_trimmed_notes_and_turns_blank_ones_into_null_with_an_audit_event(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            [$q1, $q2] = $instrument->items()->orderBy('id')->get()->all();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/notes", [
                    'notes' => [$q1->id => '  Nível 4 exige contacto visual  ', $q2->id => '   '],
                ])
                ->assertRedirect();

            $this->assertSame('Nível 4 exige contacto visual', $q1->fresh()->criterion_note);
            $this->assertNull($q2->fresh()->criterion_note);
            $this->assertSame(1, AuditEvent::where('event', 'instrument.item_notes_updated')->count());
        });
    }

    #[Test]
    public function an_unchanged_note_is_not_audited(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $q2 = $instrument->items()->orderBy('id')->get()->last();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/notes", ['notes' => [$q2->id => 'Audibilidade 5 + dicção 10']])
                ->assertRedirect();

            $this->assertSame(0, AuditEvent::where('event', 'instrument.item_notes_updated')->count());
        });
    }

    #[Test]
    public function an_item_of_another_instrument_is_refused(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $foreign = $this->scenario()->items()->orderBy('id')->firstOrFail();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/notes", ['notes' => [$foreign->id => 'Intruso']])
                ->assertStatus(422);

            $this->assertNull($foreign->fresh()->criterion_note);
        });
    }

    #[Test]
    public function an_overlong_note_is_a_validation_error(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $q1 = $instrument->items()->orderBy('id')->firstOrFail();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/notes", ['notes' => [$q1->id => str_repeat('a', 2001)]])
                ->assertSessionHasErrors();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/notes", ['notes' => [$q1->id => str_repeat('a', 2000)]])
                ->assertSessionHasNoErrors();
        });
    }

    #[Test]
    public function it_works_on_a_completed_correction(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $instrument->forceFill(['status' => 'completed'])->save();
            $q1 = $instrument->items()->orderBy('id')->firstOrFail();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/notes", ['notes' => [$q1->id => 'Critério']])
                ->assertRedirect();

            $this->assertSame('Critério', $q1->fresh()->criterion_note);
            $this->assertSame('completed', $instrument->fresh()->status->value);
        });
    }

    #[Test]
    public function a_cancelled_instrument_refuses_it(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $q1 = $instrument->items()->orderBy('id')->firstOrFail();
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Anulado.']);

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}/items/notes", ['notes' => [$q1->id => 'x']])
                ->assertForbidden();

            $this->assertNull($q1->fresh()->criterion_note);
        });
    }

    #[Test]
    public function a_colleague_who_does_not_teach_the_class_is_forbidden(): void
    {
        $instrument = $this->inTenant(fn () => $this->scenario());
        $q1 = $this->inTenant(fn () => $instrument->items()->orderBy('id')->firstOrFail());
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->withSession(['organization_id' => $this->organization->id])
            ->actingAs($colleague)
            ->put("/instruments/{$instrument->ulid}/items/notes", ['notes' => [$q1->id => 'x']])
            ->assertForbidden();

        $this->assertNull($this->inTenant(fn () => $q1->fresh()->criterion_note));
    }

    #[Test]
    public function creating_through_the_form_endpoint_saves_the_note(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'period' => $period] = $this->scenarioWithClass();

            $this->actingAs($this->user)
                ->post("/classes/{$class->ulid}/instruments", $this->payload($period, [
                    ['code' => 'Q1', 'points_possible' => 100, 'criterion_note' => '  Nível 4 exige contacto visual '],
                ]))
                ->assertRedirect();

            $item = Instrument::where('title', 'Copiado')->firstOrFail()->items()->firstOrFail();
            $this->assertSame('Nível 4 exige contacto visual', $item->criterion_note);
        });
    }

    #[Test]
    public function editing_the_element_without_the_key_never_erases_the_note(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'period' => $period] = $this->scenarioWithClass();
            $q2 = $instrument->items()->orderBy('id')->get()->last();
            $this->assertSame('Audibilidade 5 + dicção 10', $q2->criterion_note);

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}", $this->payload($period, [
                    ['ulid' => $instrument->items()->orderBy('id')->firstOrFail()->ulid, 'code' => 'Q1', 'label' => 'Novo conteúdo', 'points_possible' => 50],
                    ['ulid' => $q2->ulid, 'code' => 'Q2', 'label' => 'Outro conteúdo', 'points_possible' => 50],
                ], 'Apresentação oral'))
                ->assertSessionHasNoErrors();

            $this->assertSame('Outro conteúdo', $q2->fresh()->label);
            $this->assertSame('Audibilidade 5 + dicção 10', $q2->fresh()->criterion_note);
        });
    }

    #[Test]
    public function the_grid_and_the_import_source_carry_the_note(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'class' => $class] = $this->scenarioWithClass();

            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('can_edit', true)
                    ->where('items.0.criterion_note', null)
                    ->where('items.1.criterion_note', 'Audibilidade 5 + dicção 10'));

            $this->actingAs($this->user)->get("/classes/{$class->ulid}/instruments/create")
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('importableInstruments.0.items.1.criterion_note', 'Audibilidade 5 + dicção 10')
                    ->where('importableInstruments.0.items.0.criterion_note', null));
        });
    }

    #[Test]
    public function the_comments_page_carries_the_general_note_and_the_question_notes(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            ResultsAnalysisNote::create([
                'context_kind' => 'instrument',
                'instrument_id' => $instrument->getKey(),
                'body' => 'Turma participativa.',
                'lock_version' => 1,
                'created_by' => $this->user->id,
                'updated_by' => $this->user->id,
            ]);

            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}/comentarios")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('instruments/Comments')
                    ->where('can_edit', true)
                    ->where('cancelled', false)
                    ->where('note.body', 'Turma participativa.')
                    ->where('note.lock_version', 1)
                    ->has('items', 2)
                    ->where('items.1.criterion_note', 'Audibilidade 5 + dicção 10')
                    ->where('links.note', route('instruments.results.note', $instrument->ulid))
                    ->where('links.item_notes', route('instruments.items.notes', $instrument->ulid)));
        });
    }

    #[Test]
    public function the_comments_page_is_forbidden_to_a_stranger_and_redirects_a_draft(): void
    {
        $instrument = $this->inTenant(fn () => $this->scenario());
        $stranger = User::factory()->create();
        $this->organization->members()->attach($stranger, ['joined_at' => now()]);

        $this->withSession(['organization_id' => $this->organization->id])
            ->actingAs($stranger)->get("/instruments/{$instrument->ulid}/comentarios")->assertForbidden();

        $draft = $this->inTenant(fn () => $this->scenario('draft'));

        $this->actingAs($this->user)->get("/instruments/{$draft->ulid}/comentarios")
            ->assertRedirect(route('instruments.edit', $draft->ulid));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function payload(AcademicPeriod $period, array $items, string $title = 'Copiado'): array
    {
        return [
            'title' => $title,
            'academic_period_id' => $period->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'applied_on' => '2026-10-15',
            'submission_intent' => 'prepare',
            'purpose' => 'summative',
            'counts_toward_classification' => true,
            'total_points' => 100,
            'allow_bonus' => false,
            'items' => $items,
        ];
    }
}
