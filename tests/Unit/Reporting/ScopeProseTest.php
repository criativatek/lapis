<?php

namespace Tests\Unit\Reporting;

use App\Services\Reporting\Narrative\Scope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * THE PERIOD, SAID AS PROSE RATHER THAN PASTED IN AS A LABEL (§7).
 *
 * `Scope::clause()` already does this for the period a report is ABOUT. The
 * period a report COMPARES ITSELF TO was being assembled in the composers
 * instead — a hard-coded «Comparativamente ao» with a UI label glued to the end
 * of it. It reads correctly today only because every kind of period Portuguese
 * schools use happens to be masculine; the composition itself carries no rule
 * and nothing tested it.
 *
 * Pure: no database, no container, no report.
 */
class ScopeProseTest extends TestCase
{
    #[Test]
    public function a_semester_takes_the_masculine_article(): void
    {
        $this->assertSame('ao 1.º Semestre', Scope::previousPeriodClause('1.º Semestre'));
        $this->assertSame('ao 2.º Semestre', Scope::previousPeriodClause('2.º Semestre'));
    }

    #[Test]
    public function a_term_and_a_trimester_read_the_same_way(): void
    {
        $this->assertSame('ao 1.º Período', Scope::previousPeriodClause('1.º Período'));
        $this->assertSame('ao 2.º Período', Scope::previousPeriodClause('2.º Período'));
        $this->assertSame('ao 3.º Período', Scope::previousPeriodClause('3.º Período'));
        $this->assertSame('ao 1.º Trimestre', Scope::previousPeriodClause('1.º Trimestre'));
    }

    #[Test]
    public function a_module_reads_the_same_way(): void
    {
        $this->assertSame('ao Módulo 2', Scope::previousPeriodClause('Módulo 2'));
    }

    #[Test]
    public function an_unnamed_previous_moment_still_produces_a_sentence(): void
    {
        // A period with no label is not a period with an empty name: the
        // sentence names the moment instead of printing «ao ».
        $this->assertSame('ao momento anterior', Scope::previousPeriodClause(null));
        $this->assertSame('ao momento anterior', Scope::previousPeriodClause(''));
        $this->assertSame('ao momento anterior', Scope::previousPeriodClause('   '));
    }

    #[Test]
    public function the_clause_never_carries_its_own_preposition_twice(): void
    {
        // Whatever the caller hands over is the object, not a phrase: a label
        // that already begins with an article would otherwise print «ao ao».
        $this->assertSame('ao 1.º Semestre', Scope::previousPeriodClause('ao 1.º Semestre'));
        $this->assertSame('ao 1.º Semestre', Scope::previousPeriodClause('o 1.º Semestre'));
    }
}
