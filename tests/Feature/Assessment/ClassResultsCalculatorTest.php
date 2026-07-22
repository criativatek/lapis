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

    #[Test]
    public function accumulated_reprocesses_all_year_elements_not_period_averages(): void
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $p1 = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $p2 = $class->academicYear->periods()->where('sequence', 2)->firstOrFail();

            $calculator = app(ClassResultsCalculator::class);
            $index = fn (array $rows) => collect($rows)->keyBy(fn ($row) => $row['enrollment']->student->identity->display_name);

            $periodP1 = $index($calculator->forPeriod($class, $p1));
            $periodP2 = $index($calculator->forPeriod($class, $p2));
            $accumulated = $index($calculator->forAccumulated($class, $p2));

            // Carolina, over the whole year's raw elements — NOT the mean of her
            // two period results. Mean would be (91.302083 + 87.5)/2 = 89.401041;
            // reprocessing the elements gives 89.726562, weighted by how many
            // elements each domain actually has (§6.3, Q4).
            $carolinaAccumulated = $accumulated['Carolina Nunes']['outcome'];
            $this->assertSame('89.726562', $carolinaAccumulated->normalizedValue);
            $this->assertSame('90', $carolinaAccumulated->proposedValue);
            $this->assertNotSame(
                $periodP1['Carolina Nunes']['outcome']->normalizedValue,
                $carolinaAccumulated->normalizedValue,
            );
            $this->assertNotSame(
                $periodP2['Carolina Nunes']['outcome']->normalizedValue,
                $carolinaAccumulated->normalizedValue,
            );

            // The literal "not average of averages": Eva's period means average to
            // 66.302083, but her accumulated is 65.713140 — the earliest marks do
            // not get the disproportionate weight an average would give them.
            $evaP1 = $periodP1['Eva Salgado']['outcome']->normalizedValue;
            $evaP2 = $periodP2['Eva Salgado']['outcome']->normalizedValue;
            $evaAccumulated = $accumulated['Eva Salgado']['outcome']->normalizedValue;
            $meanOfPeriods = bcdiv(bcadd((string) $evaP1, (string) $evaP2, 6), '2', 6);
            $this->assertSame('65.713140', $evaAccumulated);
            $this->assertNotSame($meanOfPeriods, $evaAccumulated);

            // Filipe joined 03/11, after the first test: only the second-period
            // instrument reaches him, so his accumulated IS his second-period
            // result — late entry never becomes a zero, even across the year (A3).
            $filipeAccumulated = $accumulated['Filipe Andrade']['outcome'];
            $this->assertNull($periodP1['Filipe Andrade']['outcome']->normalizedValue);
            $this->assertSame(
                $periodP2['Filipe Andrade']['outcome']->normalizedValue,
                $filipeAccumulated->normalizedValue,
            );
            $this->assertSame('62.500000', $filipeAccumulated->normalizedValue);

            // Diogo was absent from the first test (no period-1 result) but present
            // in the second — his accumulated gains a value where the period had none.
            $this->assertNull($periodP1['Diogo Ferreira']['outcome']->normalizedValue);
            $this->assertSame('72.500000', $accumulated['Diogo Ferreira']['outcome']->normalizedValue);
        });
    }
}
