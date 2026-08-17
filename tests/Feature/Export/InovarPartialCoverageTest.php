<?php

namespace Tests\Feature\Export;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\RecordScores;
use App\Services\Export\InovarExportPreviewBuilder;
use App\Services\Export\InovarTemplateReader;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InovarGridFixture;
use Tests\TestCase;

/**
 * Why a result is partial, said in a way a teacher can act on.
 *
 * «1 menções resultam de cobertura parcial» is true and useless. Which student,
 * which domain, which element, when, and what was RECORDED against it — that is
 * something somebody can go and fix.
 *
 * The one thing this must never do is invent an event. A question nobody has
 * graded yet says nothing about whether anybody was there, and calling it an
 * absence would put a fact in front of a teacher that no one recorded.
 */
class InovarPartialCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
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

    /**
     * Records a state against ONE cell of the period under test.
     *
     * One cell, not the whole instrument: partial coverage is a statement about
     * a value that still exists. Mark every question and the domain has no
     * result at all, which is a different thing entirely and must be reported
     * differently.
     *
     * @param  string  $item  the item's code — Q1 is Leitura alone, Q2 is shared
     *                        between Leitura and Escrita, Q3 is Gramática
     * @return string the student's name
     */
    private function record(ResultState $state, int $studentIndex = 0, string $item = 'Q1'): string
    {
        return $this->asTenant(function () use ($state, $studentIndex, $item): string {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $enrollment = $class->enrollments()->with('student.identity')->orderBy('class_number')->get()[$studentIndex];

            // BY PERIOD, never by position: the class's instruments come back in
            // the relation's own order, and picking «the first» quietly reached
            // into the second period.
            $score = StudentItemScore::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereIn('instrument_id', $this->firstPeriodInstruments($class)->modelKeys())
                ->whereHas('item', fn (Builder $query) => $query->where('code', $item))
                ->first();

            $this->assertNotNull($score, "não existe registo de {$item} para este aluno");
            $score->update(['result_state' => $state->value, 'points_earned' => null]);

            return $enrollment->student->identity->display_name;
        });
    }

    /**
     * @return Collection<int, Instrument>
     */
    private function firstPeriodInstruments(SchoolClass $class): Collection
    {
        return $class->instruments()
            ->where('academic_period_id', $this->firstPeriod($class)->id)
            ->get();
    }

    private function firstPeriod(SchoolClass $class): AcademicPeriod
    {
        return $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
    }

    /**
     * A second element in the SAME period and the SAME domain, so a student can
     * be missing from two of them and the note has something to group.
     *
     * @return string the instrument's title
     */
    private function addSecondFirstPeriodInstrument(ResultState $state, int $studentIndex = 0): string
    {
        return $this->asTenant(function () use ($state, $studentIndex): string {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $leitura = Domain::where('code', 'LEITURA')->firstOrFail();

            $instrument = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $this->firstPeriod($class)->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Ficha de Leitura',
                'applied_on' => '2026-11-20',
                'status' => 'in_correction',
                'counts_toward_classification' => true,
                'purpose' => 'summative',
                'total_points' => 20,
            ], [
                ['code' => 'L1', 'label' => 'Leitura orientada', 'points_possible' => 20, 'domains' => [
                    ['domain_id' => $leitura->id, 'allocation_percent' => 100],
                ]],
            ]);

            $item = $instrument->items->firstOrFail();
            $cells = [];

            foreach ($class->enrollments()->orderBy('class_number')->get() as $index => $enrollment) {
                $cells[] = $index === $studentIndex
                    ? ['enrollment_id' => $enrollment->id, 'instrument_item_id' => $item->id, 'result_state' => $state->value]
                    : ['enrollment_id' => $enrollment->id, 'instrument_item_id' => $item->id, 'result_state' => ResultState::Assessed->value, 'points_earned' => 14];
            }

            app(RecordScores::class)->save($instrument, $cells, $this->teacher);

            return $instrument->title;
        });
    }

    /**
     * Fails with something readable when the demo data cannot produce the
     * situation under test, rather than with an undefined index.
     *
     * @param  list<array<string, mixed>>  $partial
     */
    private function assertProducedPartialCoverage(array $partial): void
    {
        $this->assertNotEmpty($partial, 'os dados de demonstração não produziram nenhum resultado parcial');
    }

    /**
     * The preview, over a grid naming this class's own students and domains.
     *
     * @return array<string, mixed>
     */
    private function preview(): array
    {
        return $this->asTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $this->firstPeriod($class);

            $numbers = [];
            $next = 1234;

            foreach ($class->enrollments()->with('student.identity')->orderBy('class_number')->get() as $enrollment) {
                $number = (string) $next++;
                $enrollment->student->identity->update(['school_number' => $number]);
                $numbers[$number] = $enrollment->student->identity->display_name;
            }

            $names = Domain::whereIn('id', $class->profileVersion->domains()->pluck('domain_id'))
                ->orderBy('name')->pluck('name')->all();
            $columns = [];

            foreach ($names as $index => $name) {
                $columns[chr(ord('D') + $index)] = $name;
            }

            $template = app(InovarTemplateReader::class)->read(
                (new InovarGridFixture)->build(['domains' => $columns, 'students' => $numbers]),
            );

            return app(InovarExportPreviewBuilder::class)->build($class, $period, $template);
        });
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<array<string, mixed>>
     */
    private function partial(array $preview): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $preview['summary']['partial_coverage'];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function rowFor(array $preview, string $student, string $domain): array
    {
        foreach ($this->partial($preview) as $row) {
            if ($row['student'] === $student && $row['domain'] === $domain) {
                return $row;
            }
        }

        $this->fail("não foi reportada cobertura parcial de «{$student}» em «{$domain}»");
    }

    // --------------------------------------------------- 1. a gramática

    #[Test]
    public function one_partial_result_is_told_in_the_singular(): void
    {
        $this->record(ResultState::AbsentJustified);

        $warnings = implode(' ', $this->preview()['summary']['warnings']);

        // Never «1 menções».
        $this->assertStringContainsString('resultado foi calculado com informação parcial', $warnings);
        $this->assertStringNotContainsString('menções resultam', $warnings);
    }

    #[Test]
    public function several_partial_results_are_told_in_the_plural(): void
    {
        $this->record(ResultState::AbsentJustified, studentIndex: 0, item: 'Q1');
        $this->record(ResultState::Absent, studentIndex: 1, item: 'Q1');

        $warnings = implode(' ', $this->preview()['summary']['warnings']);

        $this->assertMatchesRegularExpression('/\d+ resultados foram calculados com informação parcial/', $warnings);
    }

    // ------------------------------------------- 2. quem, onde, e porquê

    #[Test]
    public function the_student_the_domain_the_instrument_and_the_date_all_appear(): void
    {
        $name = $this->record(ResultState::AbsentJustified);
        $preview = $this->preview();

        $this->assertProducedPartialCoverage($this->partial($preview));

        // Named — not «1 resultado», which nobody can go and act on.
        $row = $this->rowFor($preview, $name, 'Leitura');
        $this->assertNotEmpty($row['elements']);

        $element = $row['elements'][0];
        $this->assertNotSame('', $element['instrument']);
        // dd/mm/aaaa, and a real date rather than an invented one.
        $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4}$/', $element['applied_on']);

        $instrument = $this->asTenant(fn (): ?Instrument => Instrument::where('title', $element['instrument'])->first());
        $this->assertNotNull($instrument);
        $this->assertSame($instrument->applied_on->format('d/m/Y'), $element['applied_on']);
    }

    #[Test]
    public function several_elements_of_the_same_domain_are_grouped_under_it(): void
    {
        // The same student missing from two different elements of Leitura. One
        // entry for the domain, one line per element — and Q2 keeps Leitura
        // alive, so there is still a result for the coverage to be partial of.
        $name = $this->record(ResultState::AbsentJustified, item: 'Q1');
        $second = $this->addSecondFirstPeriodInstrument(ResultState::Absent);

        $partial = $this->partial($this->preview());
        $keys = array_map(fn (array $row): string => $row['student'].'|'.$row['domain'], $partial);

        $this->assertSame($keys, array_values(array_unique($keys)), 'cada (aluno, domínio) aparece uma só vez');

        $leitura = array_values(array_filter(
            $partial,
            fn (array $row): bool => $row['student'] === $name && $row['domain'] === 'Leitura',
        ));

        $this->assertCount(1, $leitura);

        $instruments = array_column($leitura[0]['elements'], 'instrument');

        $this->assertContains($second, $instruments);
        $this->assertCount(2, $instruments);
        // Grouped by instrument: a student absent from a three-question test is
        // one occurrence, not three.
        $this->assertSame($instruments, array_values(array_unique($instruments)));
    }

    // ------------------------------------------------- 3. os estados

    #[Test]
    public function a_justified_absence_is_reported_as_the_state_that_was_recorded(): void
    {
        $name = $this->record(ResultState::AbsentJustified);

        // The canonical state travels; the page turns it into words.
        $this->assertSame(
            [ResultState::AbsentJustified->value],
            array_column($this->rowFor($this->preview(), $name, 'Leitura')['elements'], 'reason'),
        );
    }

    #[Test]
    public function an_unjustified_absence_is_reported_as_the_state_that_was_recorded(): void
    {
        $name = $this->record(ResultState::Absent);

        // Justified and unjustified are different facts and stay different.
        $this->assertSame(
            [ResultState::Absent->value],
            array_column($this->rowFor($this->preview(), $name, 'Leitura')['elements'], 'reason'),
        );
    }

    #[Test]
    public function an_annulled_element_does_not_by_itself_make_a_result_partial(): void
    {
        // `annulled` leaves an element out of the fraction without raising the ⚠
        // — this profile's absence rule flags absences and nothing else. Listing
        // it here would tell a teacher a result is partial when LÁPIS never said
        // it was. The word «Elemento anulado» exists for the day a profile's
        // rule does flag it; it is not manufactured here.
        $this->assertNotReported(ResultState::Annulled);
    }

    #[Test]
    public function a_non_applicable_question_does_not_by_itself_make_a_result_partial(): void
    {
        // «Não aplicável» does not enter the denominator (§13.3) — the result is
        // complete, not partial.
        $this->assertNotReported(ResultState::NotApplicable);
    }

    private function assertNotReported(ResultState $state): void
    {
        $name = $this->record($state);

        $rows = array_filter($this->partial($this->preview()), fn (array $row): bool => $row['student'] === $name);

        $this->assertSame([], array_values($rows), "{$state->value} não é cobertura parcial neste perfil");
    }

    #[Test]
    public function the_page_names_the_state_with_the_same_words_as_the_resultados_screen(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/exports/Inovar.vue'));

        // It reads the one map — it does not carry a second set of words. What
        // each state is called is asserted by CoverageTerminologyTest, once.
        $this->assertStringContainsString("import { coverageElementLine } from '@/lib/coverage'", $page);
        $this->assertStringContainsString('coverageElementLine(element)', $page);
        $this->assertStringNotContainsString('Ausência', $page);
        $this->assertStringNotContainsString('não realizou', $page);
    }

    // -------------------------------- 4. o que NUNCA pode ser inferido

    #[Test]
    public function a_question_nobody_has_graded_is_never_reported_as_an_absence(): void
    {
        // `pending` is «not graded yet», which says nothing about whether the
        // student was there. The engine does not raise coverage for it, so it
        // never reaches this list at all.
        $this->record(ResultState::Pending);

        foreach ($this->partial($this->preview()) as $row) {
            foreach ($row['elements'] as $element) {
                $this->assertNotSame(ResultState::Pending->value, $element['reason']);
            }
        }
    }

    #[Test]
    public function the_detail_comes_from_the_service_that_raises_the_warning(): void
    {
        // Reading the mentions and their coverage moved into the source when a
        // second one — a kept moment — had to answer the same question. The
        // rule did not move: it is still the service the ⚠ on Resultados reads.
        $source = (string) file_get_contents(app_path('Services/Export/CurrentPeriodResultsSource.php'));
        $builder = (string) file_get_contents(app_path('Services/Export/InovarExportPreviewBuilder.php'));

        $this->assertStringContainsString('CoverageExplanation', $source);
        $this->assertStringContainsString('$this->coverage->forResults(', $source);

        // No second rule, and no guessing at missing elements by counting —
        // in either file.
        foreach ([$source, $builder] as $contents) {
            $this->assertStringNotContainsString('raises_coverage_warning', $contents);
            $this->assertStringNotContainsString('StudentItemScore', $contents);
        }
    }

    // ------------------------------------------- 5. e nada disto bloqueia

    #[Test]
    public function partial_coverage_still_only_warns(): void
    {
        $this->record(ResultState::AbsentJustified);

        $preview = $this->preview();

        $this->assertNotEmpty($this->partial($preview));
        // A value that exists is still exported; the note is information, not a
        // refusal (§10).
        $this->assertSame([], $preview['summary']['blocking_errors']);
        $this->assertGreaterThan(0, $preview['summary']['ready_cells']);
    }

    #[Test]
    public function a_cell_with_no_mention_is_never_called_partial(): void
    {
        // Partial coverage is a statement about a value that EXISTS. The demo
        // class has whole domains with nothing recorded in this period; those
        // cells stay blank and are counted as blank, never as a partial result
        // — and never filled in with the bottom of the scale.
        $preview = $this->preview();

        $this->assertSame([], $this->partial($preview));

        $blank = array_filter($preview['values'], fn (array $value): bool => ! $value['writable']);

        $this->assertNotEmpty($blank);

        foreach ($blank as $value) {
            $this->assertNull($value['inovar_code']);
            $this->assertNull($value['qualitative_band']);
        }
    }

    #[Test]
    public function what_gets_written_is_exactly_what_it_was_before(): void
    {
        // The note explains a cell; it must not change one. Same mention, same
        // code, same writable flag — the detail rides alongside.
        $codes = fn (array $preview): array => array_map(
            fn (array $value): string => "{$value['row']}{$value['column']}={$value['inovar_code']}",
            $preview['values'],
        );

        $before = $codes($this->preview());

        $this->record(ResultState::AbsentJustified, item: 'Q3');

        $after = $this->preview();

        // Q3 is Gramática alone, so marking it changes Gramática and nothing
        // else — Leitura and Escrita come back byte for byte.
        $unchanged = array_values(array_intersect($before, $codes($after)));

        $this->assertNotEmpty($unchanged);
        $this->assertCount(count($before), $codes($after));
        // Gramática had one element; without it there is no result at all, so
        // this is the blank case rather than the partial one.
        $this->assertSame([], $this->partial($after));
    }
}
