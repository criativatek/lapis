<?php

namespace App\Services\Reporting\Narrative;

use App\Models\Report;
use App\Models\ReportScopeKind;
use DateTimeInterface;

/**
 * The stretch of time a report covers, said as a clause rather than pasted in
 * as a label (§4, §29).
 *
 * `scope_label` is a LABEL — «Ano letivo até ao momento» — and it is right for
 * a listing column and wrong inside a sentence: «reporta-se a Ano letivo até ao
 * momento» is the kind of thing that only a program writes. What a sentence
 * needs is the object of «reporta-se a …», with its article, and with a real
 * date where the scope has an open end.
 *
 * THE DATE IS THE REPORT'S OWN. A finalized report says the day it was signed;
 * a draft says today, because «até ao momento» means until this moment and
 * regenerating it tomorrow legitimately moves the end (§5).
 *
 * ARTICLE AGREEMENT. «ao» for a period, because every kind AcademicPeriodKind
 * offers is masculine in Portuguese — Semestre, Período, Trimestre, Módulo,
 * Outro. A feminine kind added there would have to be handled here.
 */
class Scope
{
    /**
     * The object of «O presente relatório reporta-se …».
     */
    public static function clause(Report $report, DateTimeInterface $generatedOn): string
    {
        return match ($report->scope_kind) {
            ReportScopeKind::Interim => 'à '.self::interimName($report),
            ReportScopeKind::Period => 'ao '.$report->scope_label,
            ReportScopeKind::DateRange => self::rangeClause($report, $generatedOn),
            // §4: an open-ended year is stated as an interval with a real end,
            // never as the words «até ao momento» left hanging.
            ReportScopeKind::Year, ReportScopeKind::Accumulated => 'ao período compreendido entre o início do ano letivo e '
                .Phrase::date($generatedOn),
        };
    }

    /**
     * The same stretch of time as an adverbial, for sentences that mention it
     * again further down: «No 2.º Semestre, …», «No período analisado, …».
     *
     * Deliberately short and deliberately varied from the opening clause: a
     * report that repeats its full temporal formula in every section reads like
     * a form (§20).
     */
    public static function shortClause(Report $report): string
    {
        return match ($report->scope_kind) {
            ReportScopeKind::Period => 'no '.$report->scope_label,
            ReportScopeKind::Interim => 'à data desta avaliação intercalar',
            default => 'no período analisado',
        };
    }

    /**
     * The object of «Comparativamente …» — the moment a report measures itself
     * against.
     *
     * HERE RATHER THAN IN THE COMPOSERS (§7). Two of them were building this by
     * hand, gluing a fixed «ao» onto a label written for a listing column. That
     * is the same mistake as «reporta-se a Ano letivo até ao momento», caught
     * once and left standing at the other end: a label is a name, not a
     * grammatical fragment, and a preposition chosen by whoever happened to
     * write the sentence is a rule nobody stated and nothing tested.
     *
     * «AO», BECAUSE EVERY SHAPE OF PERIOD THE APPLICATION OFFERS IS MASCULINE —
     * Semestre, Período, Trimestre, Módulo, Outro, all of them named in
     * AcademicPeriodKind. That is a fact about the enum, not a guess about the
     * string, and the day a feminine kind is added this is the one place that
     * has to answer for it.
     *
     * A MISSING LABEL IS STILL A MOMENT. A previous period the caller could not
     * name does not produce «ao », and it does not produce silence either: the
     * comparison happened, and the sentence says what it was made against
     * (§41).
     */
    public static function previousPeriodClause(?string $label): string
    {
        $label = trim((string) $label);

        if ($label === '') {
            return 'ao momento anterior';
        }

        // A caller that hands over «ao 1.º Semestre» meant the period, not the
        // phrase; printing «ao ao 1.º Semestre» would be the seam showing.
        $label = (string) preg_replace('/^(ao|à|o|a|no|na)\s+/ui', '', $label);

        return 'ao '.$label;
    }

    protected static function interimName(Report $report): string
    {
        $interim = $report->interimAssessment;

        if ($interim === null) {
            return 'avaliação intercalar';
        }

        return 'avaliação intercalar de '.Phrase::date($interim->reference_date);
    }

    protected static function rangeClause(Report $report, DateTimeInterface $generatedOn): string
    {
        $starts = $report->starts_on;
        $ends = $report->ends_on;

        if ($starts !== null && $ends !== null) {
            return 'ao período compreendido entre '.Phrase::date($starts).' e '.Phrase::date($ends);
        }

        if ($starts !== null) {
            return 'ao período decorrido desde '.Phrase::date($starts).' até '.Phrase::date($generatedOn);
        }

        if ($ends !== null) {
            return 'ao período decorrido até '.Phrase::date($ends);
        }

        return 'ao período compreendido entre o início do ano letivo e '.Phrase::date($generatedOn);
    }
}
