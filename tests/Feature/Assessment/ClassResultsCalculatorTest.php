<?php

namespace Tests\Feature\Assessment;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ClassResultsCalculator;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The engine end-to-end over the demonstration scenario: real instruments, a
 * late entry, an absence and unmarked cells, gathered from the database and run
 * through the pure engine.
 */
class ClassResultsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_calculates_the_demo_class_with_all_the_awkward_cases(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            $results = app(ClassResultsCalculator::class)->forPeriod($class, $period);
            $byName = collect($results)->keyBy(fn ($row) => $row['enrollment']->student->identity->display_name);

            // Carolina, weighted by domain — not a flat points sum. One reading
            // test only covers Leitura/Escrita/Gramática; Oralidade and Educação
            // Literária have no elements and are dropped, so the result
            // renormalizes over the present weights (§13.4):
            //   Leitura 93.125% (w25) · Escrita 90% (w20) · Gramática 90% (w15)
            //   → (93.125*25 + 90*20 + 90*15) / 60 = 91.302083%
            $carolina = $byName['Carolina Nunes']['outcome'];
            $this->assertSame('91.302083', $carolina->normalizedValue);
            $this->assertSame('91', $carolina->proposedValue);
            // The two uncovered domains raise a coverage warning — correct with a
            // single mid-year test, not an error.
            $this->assertTrue($carolina->coverageWarning);

            // Diogo was absent to the whole test → no result, a coverage warning,
            // and — crucially — not a zero.
            $diogo = $byName['Diogo Ferreira']['outcome'];
            $this->assertNull($diogo->normalizedValue);
            $this->assertTrue($diogo->coverageWarning);

            // Filipe joined on 03/11, after the 15/10 test → nothing applies to
            // him, so no result rather than a zero (A3).
            $filipe = $byName['Filipe Andrade']['outcome'];
            $this->assertNull($filipe->normalizedValue);

            // Eva has Q3 unmarked (pending): scored over what she has, not zeroed.
            // Q1 22/40 + Q2 19/40 assessed; Q3 (20 pts) pending → excluded.
            // Leitura: Q1 all + Q2 60% ; Escrita: Q2 40% ; Gramática: Q3 pending → no elements.
            $eva = $byName['Eva Salgado']['outcome'];
            $this->assertNotNull($eva->normalizedValue);
            $this->assertTrue($eva->coverageWarning, 'Gramática has no marked element → coverage warning.');
        });
    }
}
