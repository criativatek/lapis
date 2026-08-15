<?php

namespace Tests\Feature\Assessment;

use App\Models\Domain;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\CoverageExplanation;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the ⚠ tells the teacher, over the demonstration scenario — which already
 * holds every case that can raise one: an absence (Diogo), a late entry
 * (Filipe), an unmarked cell (Eva) and domains with no elements (Carolina).
 *
 * The point of these tests is that the note is not merely present but TRUE: an
 * absence is named, and the three things that are not absences are never
 * reported as one.
 */
class CoverageExplanationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array{overall: array<string, mixed>, domains: array<int, array<string, mixed>>}>
     */
    protected function notesByStudentName(SchoolClass $class): array
    {
        $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
        $results = app(ClassResultsCalculator::class)->forPeriod($class, $period);
        $notes = app(CoverageExplanation::class)->forResults($results);

        $byName = [];
        foreach ($results as $row) {
            $byName[$row['enrollment']->student->identity->display_name] = $notes[$row['enrollment']->getKey()];
        }

        return $byName;
    }

    protected function inDemoClass(callable $assertions): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($assertions): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            $assertions($this->notesByStudentName($class), $class);
        });
    }

    #[Test]
    public function an_absence_names_the_instrument_and_the_day_it_was_applied(): void
    {
        $this->inDemoClass(function (array $notes): void {
            // Diogo was absent from the whole test. The note has to say WHICH one,
            // not "faltam elementos".
            $absences = $notes['Diogo Ferreira']['overall']['absences'];

            $this->assertCount(1, $absences);
            $this->assertSame('Teste de Compreensão Leitora', $absences[0]['instrument']);
            $this->assertSame('15/10/2026', $absences[0]['applied_on']);

            // The recorded state travels verbatim. The page turns it into words;
            // the service never decides that "excluded" means "was absent".
            $this->assertSame('absent', $absences[0]['reason']);
        });
    }

    #[Test]
    public function a_justified_absence_is_reported_as_a_different_occurrence(): void
    {
        $this->inDemoClass(function (array $notes, SchoolClass $class): void {
            // Same student, same test, one cell reclassified as justified. The two
            // states must not collapse into a single line: they read differently
            // to a teacher and one of them is not a mark against the student.
            $enrollment = $class->enrollments()->where('class_number', 4)->firstOrFail();
            StudentItemScore::query()
                ->where('enrollment_id', $enrollment->id)
                ->orderBy('instrument_item_id')
                ->limit(1)
                ->update(['result_state' => ResultState::AbsentJustified->value]);

            $absences = $this->notesByStudentName($class)['Diogo Ferreira']['overall']['absences'];

            $reasons = array_column($absences, 'reason');
            sort($reasons);

            $this->assertSame(['absent', 'absent_justified'], $reasons);
        });
    }

    #[Test]
    public function absences_are_grouped_by_instrument_not_listed_once_per_question(): void
    {
        $this->inDemoClass(function (array $notes): void {
            // Three cells (Q1, Q2, Q3) but ONE absence: he missed a test, he did
            // not miss three things.
            $absences = $notes['Diogo Ferreira']['overall']['absences'];

            $this->assertCount(1, $absences);
            $this->assertSame(3, $absences[0]['item_count']);
        });
    }

    #[Test]
    public function an_item_split_across_two_domains_counts_once_in_the_overall_note(): void
    {
        $this->inDemoClass(function (array $notes, SchoolClass $class): void {
            $domainIds = Domain::whereIn('name', ['Leitura', 'Escrita'])->pluck('id', 'name');

            $diogo = $notes['Diogo Ferreira'];

            // Q2 is allocated 60% Leitura / 40% Escrita, so it appears in both
            // domains' notes — Leitura sees Q1+Q2, Escrita sees Q2 alone.
            $this->assertSame(2, $diogo['domains'][$domainIds['Leitura']]['absences'][0]['item_count']);
            $this->assertSame(1, $diogo['domains'][$domainIds['Escrita']]['absences'][0]['item_count']);

            // The overall note must not add them to 4: Q2 is one absence seen twice.
            $this->assertSame(3, $diogo['overall']['absences'][0]['item_count']);
        });
    }

    #[Test]
    public function a_late_entry_is_never_reported_as_an_absence(): void
    {
        $this->inDemoClass(function (array $notes): void {
            // Filipe joined on 03/11, after the 15/10 test. The engine excludes his
            // cells, but he was not absent from anything — claiming he was would
            // be an accusation the data does not support (§11.4, A3).
            $this->assertSame([], $notes['Filipe Andrade']['overall']['absences']);
        });
    }

    #[Test]
    public function an_unmarked_cell_reports_no_elements_rather_than_an_absence(): void
    {
        $this->inDemoClass(function (array $notes): void {
            $gramatica = Domain::where('name', 'Gramática')->firstOrFail()->id;

            // Eva's Q3 is simply not corrected yet. That is pending work, not a
            // missing student.
            $note = $notes['Eva Salgado']['domains'][$gramatica];

            $this->assertSame([], $note['absences']);
            $this->assertTrue($note['no_elements']);
        });
    }

    #[Test]
    public function a_domain_with_marks_and_no_absence_carries_no_note_at_all(): void
    {
        $this->inDemoClass(function (array $notes): void {
            $leitura = Domain::where('name', 'Leitura')->firstOrFail()->id;

            $note = $notes['Carolina Nunes']['domains'][$leitura];

            $this->assertSame([], $note['absences']);
            $this->assertFalse($note['no_elements']);
        });
    }

    #[Test]
    public function the_overall_note_names_the_domains_left_out_of_the_calculation(): void
    {
        $this->inDemoClass(function (array $notes): void {
            $expected = Domain::whereIn('name', ['Oralidade', 'Educação Literária'])->pluck('id')->all();

            // Carolina has a result, but it covers only three of five domains —
            // the other two carry weight and were renormalized away (§13.4). That
            // is the part of the ⚠ a teacher is least likely to guess.
            $excluded = $notes['Carolina Nunes']['overall']['excluded_domain_ids'];

            sort($expected);
            sort($excluded);
            $this->assertSame($expected, $excluded);
        });
    }

    #[Test]
    public function it_resolves_the_whole_page_without_a_query_per_student(): void
    {
        $this->inDemoClass(function (array $notes, SchoolClass $class): void {
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $results = app(ClassResultsCalculator::class)->forPeriod($class, $period);

            DB::enableQueryLog();
            app(CoverageExplanation::class)->forResults($results);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            // One lookup for every instrument named on the page, regardless of how
            // many students are flagged.
            $this->assertCount(1, $queries);
        });
    }

    #[Test]
    public function the_results_page_carries_the_note_next_to_every_warning(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        [$classUlid, $periodUlid] = app(CurrentOrganization::class)->runFor(
            $teacher->personalOrganization(),
            function (): array {
                $class = SchoolClass::where('label', '7.º A')->firstOrFail();

                return [$class->ulid, $class->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid];
            },
        );

        $this->actingAs($teacher)
            ->get("/classes/{$classUlid}/results/{$periodUlid}")
            ->assertInertia(function (AssertableInertia $page): void {
                /** @var list<array<string, mixed>> $rows */
                $rows = $page->toArray()['props']['rows'];
                $byName = collect($rows)->keyBy('name');

                $diogo = $byName['Diogo Ferreira'];
                $this->assertTrue($diogo['coverage_warning']);
                $this->assertSame(
                    'Teste de Compreensão Leitora',
                    $diogo['coverage']['absences'][0]['instrument'],
                );
                $this->assertSame('15/10/2026', $diogo['coverage']['absences'][0]['applied_on']);

                // Every domain cell carries its own note, so hovering a column
                // explains that column rather than the row's overall value.
                foreach ($diogo['domains'] as $domain) {
                    $this->assertArrayHasKey('coverage', $domain);
                }

                // Carolina has a value: hers explains what the value leaves out.
                $carolina = $byName['Carolina Nunes'];
                $this->assertSame([], $carolina['coverage']['absences']);
                $this->assertCount(2, $carolina['coverage']['excluded_domain_ids']);
            });
    }

    #[Test]
    public function explaining_a_result_does_not_move_a_single_number(): void
    {
        $this->inDemoClass(function (array $notes, SchoolClass $class): void {
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $results = app(ClassResultsCalculator::class)->forPeriod($class, $period);
            $byName = collect($results)->keyBy(fn ($row) => $row['enrollment']->student->identity->display_name);

            // The values pinned before any of this existed. Explaining a warning
            // is a statement ABOUT a result and must never be able to change it —
            // if this test ever moves, the explanation stopped being read-only.
            $this->assertSame('91.302083', $byName['Carolina Nunes']['outcome']->normalizedValue);
            $this->assertSame('91', $byName['Carolina Nunes']['outcome']->proposedValue);
            $this->assertNull($byName['Diogo Ferreira']['outcome']->normalizedValue);
            $this->assertNull($byName['Filipe Andrade']['outcome']->normalizedValue);
            $this->assertNotNull($byName['Eva Salgado']['outcome']->normalizedValue);
        });
    }

    #[Test]
    public function an_empty_result_set_asks_the_database_nothing(): void
    {
        DB::enableQueryLog();
        $notes = app(CoverageExplanation::class)->forResults([]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $notes);
        $this->assertSame([], $queries);
    }
}
