<?php

namespace App\Services\Reporting\Narrative;

/**
 * WHAT TO SAY WHEN THERE IS NOTHING TO SAY (§41).
 *
 * The single most dangerous sentence a report generator can write is a
 * confident one about data it does not have. Three distinct absences get three
 * distinct sentences here, and none of them is a reassurance:
 *
 *  - no data at all → «não existem dados suficientes», never «0%»;
 *  - no logbook entries → «não foram encontrados registos», never «não houve
 *    problemas». Nobody wrote anything down; that is not the same as nothing
 *    having happened, and a report that conflates them is inventing;
 *  - no interventions registered → «não foram registadas intervenções», never
 *    «não foram necessárias intervenções». The system knows what was recorded.
 *    It does not know what was needed.
 *
 * The distinction runs through the whole module: blank ≠ zero, no score ≠
 * absence, absence ≠ failure. These sentences are where it becomes visible to
 * the reader.
 */
class Absence
{
    /** Nothing at all could be read for this section. */
    public static function noData(?string $about = null): string
    {
        return Phrase::sentence(
            'Não existem dados suficientes',
            $about === null ? null : 'sobre '.$about,
            'no período analisado',
        );
    }

    /**
     * Nothing was written in the logbook.
     *
     * NEVER «não houve ocorrências». The absence is of records, not of events.
     */
    public static function noRecords(?string $kind = null): string
    {
        // The product's name is not in the sentence. A report is about a class,
        // not about the software that printed it (§21), and «registos» already
        // carries the distinction that matters: what was written down.
        return Phrase::sentence(
            'Não foram encontrados registos',
            $kind === null ? null : 'de '.$kind,
            'relativos ao período analisado',
        );
    }

    /** No intervention was registered. Not: none was needed. */
    public static function noInterventions(): string
    {
        return 'Não foram registadas intervenções no período analisado.';
    }

    /** Nobody has been graded yet. Not: everybody failed. */
    public static function noClassifications(): string
    {
        return 'Ainda não foram atribuídas classificações no período analisado.';
    }

    /** There is no earlier moment to compare against. Not: nothing changed. */
    public static function noComparison(): string
    {
        return 'Não existe um momento anterior com que comparar os resultados.';
    }

    /** The student answered nothing. Not: the student has no opinion. */
    public static function noSelfAssessment(): string
    {
        return 'Não foi registada autoavaliação no período analisado.';
    }

    /**
     * The teacher was asked and did not answer. Distinct from every absence
     * above: the data was never expected from the system in the first place
     * (§8), so the sentence names who has not spoken yet.
     */
    public static function notCharacterised(string $what): string
    {
        return Phrase::sentence('Não foi registada caracterização', 'de '.$what);
    }

    /**
     * Part of a class has no result. Said as a count of students, never folded
     * into an average as if it were a low score (§7 of the assessment rules).
     */
    public static function studentsWithoutResult(int $count): ?string
    {
        if ($count <= 0) {
            return null;
        }

        return $count === 1
            ? '1 aluno não tem ainda resultado apurado no período analisado, pelo que não entra nas médias apresentadas.'
            : $count.' alunos não têm ainda resultado apurado no período analisado, pelo que não entram nas médias apresentadas.';
    }
}
