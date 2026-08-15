<?php

namespace Tests\Feature\Import;

use App\Models\CorrectionImport;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\ResultState;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\StudentItemScore;
use App\Services\Assessment\InstrumentCompleteness;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Storage;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the teacher meets after a simple import lands on the grid.
 *
 * The smoke that produced this file: six students, five results imported from
 * Plickers, and one — the student the platform reported nothing for — left
 * genuinely unassessed, which is correct and must stay correct. What was NOT
 * correct was everything around it. «Concluir correção» was refused with no
 * visible reason; the unresolved row looked like every other empty box, marked
 * only by a «—»; and «Guardar» sat disabled, saying nothing, which reads as a
 * button that does not work.
 *
 * Three separate defects, one shared shape: the application knew the answer and
 * did not say it. None of them is an academic rule, and none of these tests may
 * be made to pass by changing one — a Plickers row with no score is still not a
 * zero and still not an absence.
 */
class ImportedGridPendingTest extends CorrectionImportHttpTest
{
    /** @var array<string, int> display name => enrollment id */
    protected array $roll = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The class of the smoke: six students, named, so the refusal can be
        // asserted to name the right one.
        $this->roll = app(CurrentOrganization::class)->runFor($this->organization, function (): array {
            Enrollment::where('class_id', $this->class->id)->delete();

            $names = ['Álvaro Simões', 'Marta Tomás', 'Carla Roque', 'Berta Gomes', 'Rita Vasco', 'Sandro Alves'];
            $roll = [];

            foreach ($names as $index => $name) {
                $student = Student::factory()->recycle($this->organization)->create();

                StudentIdentity::create([
                    'student_id' => $student->getKey(),
                    'organization_id' => $this->organization->getKey(),
                    'display_name' => $name,
                ]);

                $roll[$name] = (int) Enrollment::factory()->recycle($this->organization)->create([
                    'class_id' => $this->class->id,
                    'student_id' => $student->getKey(),
                    'class_number' => $index + 1,
                    'enrolled_on' => now()->subMonths(6)->toDateString(),
                ])->getKey();
            }

            return $roll;
        });
    }

    /**
     * The smoke, end to end: analyse the six-student export, map every row, and
     * import the platform's own classification. Helena — the row Plickers marks
     * «-» throughout — is the one nobody has a result for.
     */
    protected function importSixStudents(): Instrument
    {
        Storage::fake('local');

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => 'plickers',
            'file' => $this->fixture('plickers-seis-alunos.csv'),
        ])->assertRedirect();

        $import = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => CorrectionImport::latest('id')->firstOrFail(),
        );

        $mapping = $this->overallMapping();
        $mapping['students'] = [
            'student:1' => $this->roll['Álvaro Simões'],
            // Card 2 is Eva, who scored 50%; card 5 is Helena, who took no part.
            'student:2' => $this->roll['Carla Roque'],
            'student:3' => $this->roll['Berta Gomes'],
            'student:4' => $this->roll['Rita Vasco'],
            'student:5' => $this->roll['Marta Tomás'],
            'student:6' => $this->roll['Sandro Alves'],
        ];

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        return app(CurrentOrganization::class)->runFor($this->organization, fn () => Instrument::firstOrFail());
    }

    /**
     * @return array<string, mixed>
     */
    protected function gridProps(Instrument $instrument): array
    {
        return $this->actingAs($this->teacher)
            ->get("/instruments/{$instrument->ulid}")
            ->assertOk()
            ->viewData('page')['props'];
    }

    protected function grid(): string
    {
        return (string) file_get_contents(base_path('resources/js/pages/instruments/Grid.vue'));
    }

    /**
     * The body of one top-level function in the grid component, so an assertion
     * about a handler cannot be satisfied by something written elsewhere in a
     * seven-hundred-line file.
     */
    protected function bodyOf(string $function): string
    {
        $grid = $this->grid();
        $start = strpos($grid, "function {$function}(");

        $this->assertNotFalse($start, "A função {$function}() tem de existir na grelha.");

        // Top-level functions are closed by a brace in the first column.
        $end = strpos($grid, "\n}", (int) $start);

        return substr($grid, (int) $start, (int) $end - (int) $start);
    }

    // ------------------------------------------- 1, 2. what actually persisted

    #[Test]
    public function the_imported_results_are_persisted_and_the_one_without_a_score_is_not(): void
    {
        $instrument = $this->importSixStudents();

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): void {
            $marks = StudentItemScore::where('instrument_id', $instrument->getKey())
                ->get()->mapWithKeys(fn (StudentItemScore $score): array => [
                    $score->enrollment_id => (string) $score->points_earned,
                ]);

            // Five real marks, on disk, from the platform's own scores.
            $this->assertCount(5, $marks);
            $this->assertSame('100.0000', $marks[$this->roll['Álvaro Simões']]);
            $this->assertSame('50.0000', $marks[$this->roll['Carla Roque']]);
            $this->assertSame('0.0000', $marks[$this->roll['Rita Vasco']]);

            // And no row at all for the student the platform reported nothing
            // for. Not a zero, not an absence, not a stored blank (§4).
            $this->assertArrayNotHasKey($this->roll['Marta Tomás'], $marks->all());

            $this->assertSame(InstrumentStatus::InCorrection, $instrument->fresh()->status);
        });
    }

    #[Test]
    public function the_completeness_service_names_that_one_student_and_nobody_else(): void
    {
        $instrument = $this->importSixStudents();

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): void {
            $completeness = app(InstrumentCompleteness::class);
            $progress = $completeness->for($instrument);

            $this->assertSame(6, $progress['applicable']);
            $this->assertSame(5, $progress['completed']);
            $this->assertFalse($progress['complete']);
            $this->assertSame(1, $completeness->pendingCount($instrument));

            // The count and the names come from the same predicate, so this is
            // the same student the refusal is about.
            $this->assertSame([$this->roll['Marta Tomás']], $completeness->pendingEnrollmentIds($instrument));
        });
    }

    // --------------------------------------- 3, 4. blocked, and it says why

    #[Test]
    public function completing_is_refused_while_that_student_is_unresolved(): void
    {
        $instrument = $this->importSixStudents();

        $this->actingAs($this->teacher)
            ->post("/instruments/{$instrument->ulid}/complete")
            ->assertSessionHasErrors('status');

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): void {
            // Refused on the server, not merely discouraged in the interface.
            $this->assertSame(InstrumentStatus::InCorrection, $instrument->fresh()->status);
        });
    }

    #[Test]
    public function the_reason_and_the_name_reach_the_page(): void
    {
        $instrument = $this->importSixStudents();

        $this->actingAs($this->teacher)
            ->get("/instruments/{$instrument->ulid}")
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props']['instrument'];

                $this->assertFalse($props['can_complete']);
                $this->assertSame(1, $props['pending_count']);
                $this->assertSame(6, $props['applicable_count']);

                // The whole point: not «1 por resolver» and good luck, but who.
                $this->assertSame(['Marta Tomás'], $props['pending_students']);
            });
    }

    #[Test]
    public function the_page_shows_the_reason_rather_than_hiding_it_in_a_tooltip(): void
    {
        $grid = $this->componentSource('resources/js/pages/instruments/Grid.vue');

        // A `title` on a DISABLED button is very nearly invisible — browsers
        // suppress pointer events on disabled controls, so the tooltip usually
        // never appears. The reason is rendered on the page now.
        $this->assertStringContainsString('completeBlockedReason', $grid);
        $this->assertStringContainsString('Falta resolver 1 resultado antes de concluir a correção.', $grid);
        $this->assertStringContainsString('Faltam resolver ${count} resultados antes de concluir a correção.', $grid);
        // Stops before the closing tag on purpose: Prettier moves a trailing
        // `>` onto its own line, and where the bracket sits is not the claim.
        $this->assertStringContainsString('Por avaliar: <strong>{{ pendingNamesText }}', $grid);

        // And never an inference the teacher did not make.
        $this->assertStringNotContainsString('Faltou', $grid);
    }

    // ------------------------------------------------ 5. the row is findable

    #[Test]
    public function the_unresolved_row_says_por_avaliar_instead_of_a_dash(): void
    {
        $grid = $this->componentSource('resources/js/pages/instruments/Grid.vue');

        $this->assertMatchesRegularExpression("/pending: 'Por avaliar'/", $grid);
        $this->assertStringContainsString('isUnresolved(student)', $grid);
        $this->assertStringContainsString('isPendingCell(student, item)', $grid);

        // The dash as the pending label is what let one unresolved student hide
        // among five corrected ones.
        $this->assertStringNotContainsString('<option value="pending">—</option>', $grid);
    }

    #[Test]
    public function the_grid_learns_what_resolved_means_from_the_server(): void
    {
        $instrument = $this->importSixStudents();

        $states = collect($this->gridProps($instrument)['states'])->keyBy('value');

        // One list, shipped — not a second copy in TypeScript that would one day
        // disagree with InstrumentCompleteness about what «por avaliar» means.
        $this->assertFalse($states['pending']['resolves']);
        $this->assertFalse($states['under_review']['resolves']);

        foreach (['assessed', 'absent', 'absent_justified', 'exempt', 'not_applicable', 'annulled'] as $resolved) {
            $this->assertTrue($states[$resolved]['resolves'], "«{$resolved}» resolve a célula.");
        }
    }

    #[Test]
    public function no_new_state_was_invented_for_the_platforms_missing_score(): void
    {
        $instrument = $this->importSixStudents();

        $values = collect($this->gridProps($instrument)['states'])->pluck('value')->all();

        // The teacher resolves the row with the states the model already has.
        // «Não participou no Plickers» is provenance, not an academic state, and
        // adding one would put a provider's vocabulary into the domain (§4).
        $this->assertSame(array_map(fn (ResultState $state): string => $state->value, ResultState::cases()), $values);
    }

    // ------------------------------------------------- 6, 7, 8. saving

    #[Test]
    public function saving_says_so(): void
    {
        $instrument = $this->importSixStudents();

        $itemId = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): int => (int) $instrument->items()->firstOrFail()->id,
        );

        $this->actingAs($this->teacher)
            ->post("/instruments/{$instrument->ulid}/scores", [
                'cells' => [[
                    'enrollment_id' => $this->roll['Marta Tomás'],
                    'instrument_item_id' => $itemId,
                    'result_state' => ResultState::Absent->value,
                    'points_earned' => null,
                ]],
            ])
            ->assertRedirect()
            // Every other action on this page flashed; saving was the one that
            // did not, so a successful save looked exactly like nothing
            // happening — the counter vanished and nothing replaced it (§5).
            ->assertSessionHas(SessionKey::FLASH_DATA, [
                'toast' => ['type' => 'success', 'message' => 'Alterações guardadas.'],
            ]);
    }

    #[Test]
    public function a_save_with_nothing_to_save_is_prevented_rather_than_silent(): void
    {
        $grid = $this->componentSource('resources/js/pages/instruments/Grid.vue');

        // Preference A: the button is disabled AND the page says why. It was
        // already disabled; what was missing was anything explaining it.
        $this->assertStringContainsString('Sem alterações por guardar.', $grid);
        // The condition, not the attribute quoting: Prettier puts a long binding
        // value on its own line, which changes the punctuation and not the rule.
        $this->assertStringContainsString('dirtyCount === 0 || saving || hasOverMaxCell', $grid);
        $this->assertStringContainsString(':title="saveHint"', $grid);

        // The server refuses an empty batch too, so a stale client cannot make
        // a no-op look like a save.
        $instrument = $this->importSixStudents();

        $this->actingAs($this->teacher)
            ->post("/instruments/{$instrument->ulid}/scores", ['cells' => []])
            ->assertSessionHasErrors('cells');
    }

    #[Test]
    public function editing_a_cell_or_a_state_marks_the_grid_dirty_and_saving_clears_it(): void
    {
        // Both ways of resolving a row have to count as a change: typing a mark
        // and choosing a state.
        foreach (['onPointsInput', 'onStateChange'] as $handler) {
            $this->assertStringContainsString('markDirty(', $this->bodyOf($handler), "{$handler} tem de marcar a grelha como alterada.");
        }

        $grid = $this->grid();
        $this->assertStringContainsString('onSuccess: () => dirty.clear()', $grid);

        // And the two buttons read that same state.
        $this->assertStringContainsString('if (dirtyCount.value > 0)', $grid);
    }

    // --------------------------------- 9, 10. resolving her, and only then

    #[Test]
    public function resolving_the_last_student_unblocks_completing_and_completing_stays_explicit(): void
    {
        $instrument = $this->importSixStudents();

        $itemId = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): int => (int) $instrument->items()->firstOrFail()->id,
        );

        // The state is chosen HERE, by the test standing in for the teacher.
        // Nothing in the application picks one: «absent» is a pedagogical claim
        // and only a person may make it (§7, §3.3).
        $this->actingAs($this->teacher)->post("/instruments/{$instrument->ulid}/scores", [
            'cells' => [[
                'enrollment_id' => $this->roll['Marta Tomás'],
                'instrument_item_id' => $itemId,
                'result_state' => ResultState::Absent->value,
                'points_earned' => null,
            ]],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): void {
            $completeness = app(InstrumentCompleteness::class);

            $this->assertTrue($completeness->for($instrument->fresh())['complete']);
            $this->assertSame([], $completeness->pendingEnrollmentIds($instrument->fresh()));

            // Resolved, and still NOT concluded — saving persists work, it never
            // declares the work over.
            $this->assertSame(InstrumentStatus::InCorrection, $instrument->fresh()->status);
        });

        $this->actingAs($this->teacher)
            ->get("/instruments/{$instrument->ulid}")
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props']['instrument'];

                $this->assertTrue($props['can_complete']);
                $this->assertSame(0, $props['pending_count']);
                $this->assertSame([], $props['pending_students']);
            });

        // Now the explicit act.
        $this->actingAs($this->teacher)
            ->post("/instruments/{$instrument->ulid}/complete")
            ->assertSessionHasNoErrors();

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): void {
            $fresh = $instrument->fresh();

            $this->assertSame(InstrumentStatus::Completed, $fresh->status);
            $this->assertNotNull($fresh->completed_at);
            $this->assertSame($this->teacher->getKey(), $fresh->completed_by);

            // And the marks are untouched by concluding: five imported results,
            // one recorded absence, no value invented for either.
            $this->assertSame(6, StudentItemScore::where('instrument_id', $fresh->getKey())->count());
            $this->assertNull(
                StudentItemScore::where('instrument_id', $fresh->getKey())
                    ->where('enrollment_id', $this->roll['Marta Tomás'])
                    ->firstOrFail()->points_earned,
            );
        });
    }

    #[Test]
    public function an_absence_recorded_by_the_teacher_is_never_a_zero(): void
    {
        $instrument = $this->importSixStudents();

        $itemId = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): int => (int) $instrument->items()->firstOrFail()->id,
        );

        $this->actingAs($this->teacher)->post("/instruments/{$instrument->ulid}/scores", [
            'cells' => [[
                'enrollment_id' => $this->roll['Marta Tomás'],
                'instrument_item_id' => $itemId,
                'result_state' => ResultState::Absent->value,
                // A stale client trying to smuggle a value onto an absence.
                'points_earned' => 0,
            ]],
        ]);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): void {
            $score = StudentItemScore::where('instrument_id', $instrument->getKey())
                ->where('enrollment_id', $this->roll['Marta Tomás'])
                ->firstOrFail();

            $this->assertSame(ResultState::Absent, $score->result_state);
            $this->assertNull($score->points_earned, 'Uma ausência não carrega valor (§12.4).');
        });
    }
}
