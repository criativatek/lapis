<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\InstrumentStatus;
use App\Models\ItemDomainAllocation;
use App\Models\Organization;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\InstrumentCompleteness;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Marking a whole student absent, and reading a dense grid.
 *
 * A student who missed the test missed every question of it, and choosing
 * «AusJ» seventeen times is not something anybody does twice. The shortcut
 * added to the grid writes exactly the per-item states the teacher would have
 * chosen by hand — same endpoint, same transaction, same guards — so what is
 * worth testing is the SEMANTICS it relies on: that a state never becomes a
 * zero, never invents a mark, and never reaches another student's row.
 */
class InstrumentGridRowStateTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected SchoolClass $class;

    protected AcademicPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        // Reference data comes from the base TestCase, which seeds it for every
        // test that refreshes the database.
        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();

        [$this->class, $this->period] = app(CurrentOrganization::class)->runFor(
            $this->organization,
            function (): array {
                $year = AcademicYear::factory()->recycle($this->organization)->create();
                $period = AcademicPeriod::factory()->recycle($this->organization)->for($year)->create();

                $class = SchoolClass::factory()->recycle($this->organization)->create([
                    'academic_year_id' => $year->id,
                    'subject_id' => Subject::factory()->recycle($this->organization)->create()->id,
                ]);
                $class->teachers()->attach($this->teacher, ['role' => 'owner']);

                foreach ([1, 2, 3] as $number) {
                    Enrollment::factory()->recycle($this->organization)->create([
                        'class_id' => $class->id,
                        'class_number' => $number,
                        'enrolled_on' => now()->subMonths(6)->toDateString(),
                    ]);
                }

                return [$class, $period];
            },
        );
    }

    protected function grid(): string
    {
        return (string) file_get_contents(base_path('resources/js/pages/instruments/Grid.vue'));
    }

    protected function collapsed(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    // ------------------------------------------------------- 1. density (§2)

    #[Test]
    public function the_rows_are_dense_enough_for_a_whole_class(): void
    {
        $grid = $this->grid();

        // The controls drove the row height: 32px boxes with 6px of padding
        // above and below made a thirty-student class into a scrolling job.
        $this->assertStringContainsString('h-7 w-16 rounded border', $grid, 'a caixa da nota tem de ser compacta');
        $this->assertStringContainsString('h-7 cursor-pointer rounded border', $grid, 'o seletor por item tem de ser compacto');
        $this->assertStringNotContainsString('h-8 w-16 rounded border', $grid);

        // And the cells stopped padding the rows out.
        $this->assertStringContainsString('px-1 py-0.5 text-center', $grid);
        $this->assertStringNotContainsString('px-1 py-1 text-center', $grid);

        // The header keeps its own breathing room: it carries the item code,
        // the cotação and the domain, and those still have to be readable (§2).
        $this->assertStringContainsString('px-2 py-2 text-center font-medium', $grid);
    }

    // ------------------------------------------- 2. the row control (§3, §6, §9)

    #[Test]
    public function each_student_gets_exactly_one_state_control_for_the_whole_evaluation(): void
    {
        $collapsed = $this->collapsed($this->grid());

        // One control, beside the name, inside the student cell — not a
        // seventeenth column on a table that is already very wide (§9).
        $this->assertSame(1, substr_count($collapsed, 'onRowStateChange(student,'));
        $this->assertStringContainsString(
            'Estado de ${student.name} em toda a avaliação',
            $collapsed,
        );

        // Offered states are «everything that does not carry a value», derived
        // and never listed: a state that carries one needs a number per
        // question, and a bulk action has none to give (§3).
        $this->assertStringContainsString(
            'const rowStates = computed(() => nonAssessedStates.value)',
            $collapsed,
        );

        // Disagreement is shown as disagreement (§6).
        $this->assertStringContainsString('Vários', $collapsed);
        $this->assertStringContainsString('rowStateOf(student) === MIXED', $collapsed);
    }

    #[Test]
    public function clearing_a_row_leaves_the_marks_that_were_actually_earned(): void
    {
        // «Sem estado» undoes an absence, not a correction: a question marked 14
        // stays marked 14, and only the cells that never held a classification
        // return to «por avaliar» (§7).
        $this->assertStringContainsString(
            "if (state === 'pending' && current.state === 'assessed') { continue; }",
            $this->collapsed($this->grid()),
        );
    }

    #[Test]
    public function applying_a_state_over_existing_marks_asks_first(): void
    {
        $collapsed = $this->collapsed($this->grid());

        // Only when there is something to lose — applying a state to a question
        // that holds a mark removes the mark, and doing that to eight at once
        // in silence is a loss dressed as a shortcut (§8).
        $this->assertStringContainsString("if (marked > 0 && state !== 'pending')", $collapsed);
        $this->assertStringContainsString('Aplicar a toda a avaliação?', $collapsed);
        $this->assertStringContainsString('Aplicar a todas', $collapsed);
    }

    // ------------------------------------------------- 3. what the bulk writes

    #[Test]
    public function a_whole_row_of_absences_creates_no_zeros_and_no_marks(): void
    {
        [$instrument, $enrollments, $items] = $this->makeInstrument();

        $absent = $enrollments->first();

        // Exactly what the shortcut sends: one state per item, in one request.
        $this->saveCells($instrument, $items->map(fn (InstrumentItem $item): array => [
            'enrollment_id' => $absent->id,
            'instrument_item_id' => $item->id,
            'result_state' => ResultState::AbsentJustified->value,
        ])->all());

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument, $absent, $items): void {
            $scores = StudentItemScore::where('instrument_id', $instrument->id)
                ->where('enrollment_id', $absent->id)
                ->get();

            $this->assertCount($items->count(), $scores, 'todas as perguntas ficam com estado');

            foreach ($scores as $score) {
                $this->assertSame(ResultState::AbsentJustified, $score->result_state);
                // Not a zero. Not a mark. An absence is a lack of data, and the
                // only state that may carry a number is «avaliado».
                $this->assertNull($score->points_earned);
                $this->assertNull($score->assessed_at);
            }
        });
    }

    #[Test]
    public function marking_one_student_absent_leaves_every_other_student_alone(): void
    {
        [$instrument, $enrollments, $items] = $this->makeInstrument();

        $absent = $enrollments->first();
        $other = $enrollments->last();

        $this->saveCells($instrument, [[
            'enrollment_id' => $other->id,
            'instrument_item_id' => $items->first()->id,
            'result_state' => ResultState::Assessed->value,
            'points_earned' => 12,
        ]]);

        $this->saveCells($instrument, $items->map(fn (InstrumentItem $item): array => [
            'enrollment_id' => $absent->id,
            'instrument_item_id' => $item->id,
            'result_state' => ResultState::Absent->value,
        ])->all());

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument, $other, $items): void {
            $untouched = StudentItemScore::where('instrument_id', $instrument->id)
                ->where('enrollment_id', $other->id)
                ->where('instrument_item_id', $items->first()->id)
                ->firstOrFail();

            $this->assertSame(ResultState::Assessed, $untouched->result_state);
            $this->assertSame('12.0000', (string) $untouched->points_earned);
        });
    }

    #[Test]
    public function clearing_a_row_removes_the_states_and_keeps_a_real_zero(): void
    {
        [$instrument, $enrollments, $items] = $this->makeInstrument();
        $student = $enrollments->first();

        // A genuine zero on the first question, absences on the rest.
        $this->saveCells($instrument, [
            ['enrollment_id' => $student->id, 'instrument_item_id' => $items[0]->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => 0],
            ['enrollment_id' => $student->id, 'instrument_item_id' => $items[1]->id, 'result_state' => ResultState::Absent->value],
            ['enrollment_id' => $student->id, 'instrument_item_id' => $items[2]->id, 'result_state' => ResultState::Absent->value],
        ]);

        // Clearing sends «pending» for the absences only — the assessed cell is
        // skipped by the shortcut, exactly as the component does.
        $this->saveCells($instrument, [
            ['enrollment_id' => $student->id, 'instrument_item_id' => $items[1]->id, 'result_state' => ResultState::Pending->value],
            ['enrollment_id' => $student->id, 'instrument_item_id' => $items[2]->id, 'result_state' => ResultState::Pending->value],
        ]);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($student, $items): void {
            // The zero survived: it was earned, and it is not an absence.
            $zero = StudentItemScore::where('instrument_item_id', $items[0]->id)
                ->where('enrollment_id', $student->id)->firstOrFail();
            $this->assertSame(ResultState::Assessed, $zero->result_state);
            $this->assertSame('0.0000', (string) $zero->points_earned);

            // The cleared cells have no row at all — «por avaliar» is the
            // absence of a row, never a stored blank.
            $this->assertSame(0, StudentItemScore::where('enrollment_id', $student->id)
                ->whereIn('instrument_item_id', [$items[1]->id, $items[2]->id])
                ->count());
        });
    }

    // ------------------------------------------- 3b. why completion is refused

    #[Test]
    public function an_instrument_nobody_was_enrolled_for_says_so_instead_of_counting_to_zero(): void
    {
        $collapsed = $this->collapsed($this->grid());

        // «Faltam resolver 0 resultados» was the true count answering the wrong
        // question: an instrument dated before anybody enrolled applies to
        // nobody, so there is nothing to resolve AND nothing to conclude. The
        // refusal now names the date and the way out (§2).
        $this->assertStringContainsString('props.instrument.applicable_count === 0', $collapsed);
        $this->assertStringContainsString('nenhum aluno da turma estava inscrito nessa data', $collapsed);
        $this->assertStringContainsString('Corrija a data da avaliação', $collapsed);

        // And no path can reach a «faltam 0» sentence again.
        $this->assertStringContainsString('if (count <= 0)', $collapsed);
    }

    #[Test]
    public function an_instrument_dated_before_the_class_existed_applies_to_nobody(): void
    {
        [$instrument, $enrollments] = $this->makeInstrument();

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument, $enrollments): void {
            // Dated a year before anybody enrolled — the shape of the smoke
            // instrument that produced the report.
            $instrument->update(['applied_on' => $enrollments->first()->enrolled_on->copy()->subYear()->toDateString()]);

            $progress = app(InstrumentCompleteness::class)->for($instrument->fresh());

            $this->assertSame(0, $progress['applicable']);
            $this->assertFalse($progress['complete'], 'não é concluível…');
            // …e a contagem é honestamente zero, que é exactly why the message
            // may not be built from it.
            $this->assertSame(0, app(InstrumentCompleteness::class)->pendingCount($instrument->fresh()));
        });
    }

    // ------------------------------- 3c. an empty cell is never «avaliado»

    #[Test]
    public function a_column_with_one_annulled_row_and_the_rest_empty_saves_without_a_500(): void
    {
        [$instrument, $enrollments, $items] = $this->makeInstrument();

        // The deduction column of the smoke instrument, in miniature: every cell
        // empty, one of them annulled. The empty ones used to travel as
        // «assessed» with no value, which the model refuses — a 500 rather than
        // a wrong mark, but a 500 all the same.
        $column = $items->first();

        $cells = [[
            'enrollment_id' => $enrollments->first()->id,
            'instrument_item_id' => $column->id,
            'result_state' => ResultState::Annulled->value,
            'points_earned' => null,
            'lock_version' => 0,
        ]];

        foreach ($enrollments->skip(1) as $enrollment) {
            $cells[] = [
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $column->id,
                // What the corrected payload builder sends for an empty cell.
                'result_state' => ResultState::Pending->value,
                'points_earned' => null,
                'lock_version' => 0,
            ];
        }

        $this->actingAs($this->teacher)
            ->post("/instruments/{$instrument->ulid}/scores", ['cells' => $cells])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($column, $enrollments): void {
            $annulled = StudentItemScore::where('instrument_item_id', $column->id)
                ->where('enrollment_id', $enrollments->first()->id)
                ->firstOrFail();

            $this->assertSame(ResultState::Annulled, $annulled->result_state);
            $this->assertNull($annulled->points_earned);

            // The empty ones stored nothing at all — «por avaliar» is the
            // absence of a row, not a stored blank.
            $this->assertSame(0, StudentItemScore::where('instrument_item_id', $column->id)
                ->whereIn('enrollment_id', $enrollments->skip(1)->pluck('id'))
                ->count());
        });
    }

    #[Test]
    public function the_server_still_refuses_an_assessed_cell_with_no_value(): void
    {
        [$instrument, $enrollments, $items] = $this->makeInstrument();

        // The guard the interface must never need — and which is NOT weakened.
        // If anything ever sends this pair again it is refused, loudly.
        $this->actingAs($this->teacher)
            ->post("/instruments/{$instrument->ulid}/scores", [
                'cells' => [[
                    'enrollment_id' => $enrollments->first()->id,
                    'instrument_item_id' => $items->first()->id,
                    'result_state' => ResultState::Assessed->value,
                    'points_earned' => null,
                    'lock_version' => 0,
                ]],
            ])
            ->assertServerError();
    }

    #[Test]
    public function a_written_zero_is_still_a_zero_and_not_an_empty_cell(): void
    {
        [$instrument, $enrollments, $items] = $this->makeInstrument();

        $this->saveCells($instrument, [[
            'enrollment_id' => $enrollments->first()->id,
            'instrument_item_id' => $items->first()->id,
            'result_state' => ResultState::Assessed->value,
            'points_earned' => 0,
        ]]);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($items, $enrollments): void {
            $zero = StudentItemScore::where('instrument_item_id', $items->first()->id)
                ->where('enrollment_id', $enrollments->first()->id)
                ->firstOrFail();

            $this->assertSame(ResultState::Assessed, $zero->result_state);
            $this->assertSame('0.0000', (string) $zero->points_earned);
        });
    }

    #[Test]
    public function nothing_in_the_grid_can_send_an_assessed_cell_without_a_number(): void
    {
        $grid = $this->collapsed($this->grid());

        // Every path that sets «assessed» now requires a finite number, and the
        // payload builder refuses the pair one last time before the wire.
        $this->assertStringContainsString(
            "const assessed = current.state === 'assessed' && Number.isFinite(current.points);",
            $grid,
        );
        $this->assertStringContainsString(
            "if (state === 'assessed' && ! Number.isFinite(current.points))",
            $grid,
        );
        $this->assertStringContainsString('if (parsed === null || ! Number.isFinite(parsed))', $grid);

        // Number.isFinite and never truthiness, because 0 is a mark.
        $this->assertStringNotContainsString('current.points ?', $grid);
    }

    #[Test]
    public function the_deduction_column_accepts_the_negative_it_exists_for(): void
    {
        $grid = $this->collapsed($this->grid());

        // `min="0"` made the one value a deduction holds impossible to type, and
        // a half-typed «-» was how the empty cells became «assessed» in the
        // first place.
        $this->assertStringContainsString(':min="minimumFor(item)"', $grid);
        $this->assertStringNotContainsString('type="number" step="0.25" min="0"', $grid);
        $this->assertStringContainsString(
            'return item.is_bonus && item.points_possible === 0;',
            $grid,
        );
    }

    // ------------------------------------------------------------ 4. security

    #[Test]
    public function a_row_of_states_cannot_be_written_onto_another_instruments_items(): void
    {
        [$instrument, $enrollments] = $this->makeInstrument();
        [$other, , $otherItems] = $this->makeInstrument();

        $this->actingAs($this->teacher)
            ->post("/instruments/{$instrument->ulid}/scores", [
                'cells' => [[
                    'enrollment_id' => $enrollments->first()->id,
                    'instrument_item_id' => $otherItems->first()->id,
                    'result_state' => ResultState::Absent->value,
                    'lock_version' => 0,
                ]],
            ])
            ->assertStatus(422);

        app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => $this->assertSame(0, StudentItemScore::where('instrument_id', $other->id)->count()),
        );
    }

    #[Test]
    public function a_teacher_of_another_class_cannot_mark_this_row_at_all(): void
    {
        [$instrument, $enrollments, $items] = $this->makeInstrument();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post("/instruments/{$instrument->ulid}/scores", [
                'cells' => [[
                    'enrollment_id' => $enrollments->first()->id,
                    'instrument_item_id' => $items->first()->id,
                    'result_state' => ResultState::Absent->value,
                    'lock_version' => 0,
                ]],
            ])
            ->assertNotFound();

        app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => $this->assertSame(0, StudentItemScore::where('instrument_id', $instrument->id)->count()),
        );
    }

    // ------------------------------------------------------------ 5. helpers

    /**
     * @param  list<array<string, mixed>>  $cells
     */
    protected function saveCells(Instrument $instrument, array $cells): void
    {
        $cells = app(CurrentOrganization::class)->runFor($this->organization, function () use ($cells): array {
            return array_map(function (array $cell): array {
                $score = StudentItemScore::query()
                    ->where('instrument_item_id', $cell['instrument_item_id'])
                    ->where('enrollment_id', $cell['enrollment_id'])
                    ->first();

                return [...$cell, 'lock_version' => $score?->lock_version ?? 0];
            }, $cells);
        });

        $this->actingAs($this->teacher)
            ->post("/instruments/{$instrument->ulid}/scores", ['cells' => $cells])
            ->assertRedirect();
    }

    /**
     * @return array{0: Instrument, 1: Collection<int, Enrollment>, 2: Collection<int, InstrumentItem>}
     */
    protected function makeInstrument(): array
    {
        return app(CurrentOrganization::class)->runFor($this->organization, function (): array {
            $domain = Domain::factory()->recycle($this->organization)->create();

            $instrument = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $this->class->id,
                'academic_period_id' => $this->period->id,
                'status' => InstrumentStatus::InCorrection->value,
            ]);

            $items = collect(range(1, 3))->map(function (int $sequence) use ($instrument, $domain): InstrumentItem {
                $item = InstrumentItem::factory()->recycle($this->organization)->create([
                    'instrument_id' => $instrument->id,
                    'code' => 'Q'.$sequence.'-'.$instrument->id,
                    'sequence' => $sequence,
                    'points_possible' => 20,
                ]);

                ItemDomainAllocation::create([
                    'instrument_item_id' => $item->id,
                    'domain_id' => $domain->id,
                    'allocation_percent' => 100,
                ]);

                return $item;
            });

            $enrollments = Enrollment::where('class_id', $this->class->id)->orderBy('class_number')->get();

            return [$instrument->fresh(), $enrollments, $items];
        });
    }
}
