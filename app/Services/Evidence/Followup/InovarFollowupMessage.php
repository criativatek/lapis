<?php

namespace App\Services\Evidence\Followup;

use App\Models\RecordFollowupCategory;
use App\Support\Language\PortugueseCardinal;
use Carbon\CarbonInterface;

/**
 * The impersonal text a teacher copies into an Inovar occurrence (nível 2) —
 * the three models the product owner wrote (2026-10-12), filled from the
 * records. Prepared here, edited in the browser, NEVER stored and never sent
 * anywhere: editing it changes nothing in the logbook.
 *
 * It always describes exactly the occurrences it is given: four of them read
 * «quatro» with four dates, never «três». Two occurrences on the same day are
 * one date with «(duas ocorrências)» — never two days.
 *
 * A missing student number or name drops that piece of the sentence (no
 * «null», no stray comma) and is reported in `missing`, so the screen asks
 * the teacher to review the identification before copying.
 */
final class InovarFollowupMessage
{
    /**
     * @param  list<CarbonInterface>  $dates  one per occurrence, any order
     * @return array{text: string, missing: list<string>}
     */
    public static function compose(
        RecordFollowupCategory $category,
        ?int $classNumber,
        ?string $studentName,
        string $subject,
        string $windowPhrase,
        array $dates,
    ): array {
        $studentName = $studentName === null || trim($studentName) === '' ? null : trim($studentName);
        $count = count($dates);
        $quantity = PortugueseCardinal::feminine($count);
        $datesPhrase = self::datesPhrase($dates);

        $missing = [];

        if ($classNumber === null) {
            $missing[] = 'number';
        }

        if ($studentName === null) {
            $missing[] = 'name';
        }

        $text = match ($category) {
            RecordFollowupCategory::MissingMaterial => sprintf(
                '%s acumulou %s %s na disciplina de %s, %s, %s. %s',
                self::subjectPhrase('O aluno', $classNumber, $studentName),
                $quantity,
                $count === 1 ? 'falta de material' : 'faltas de material',
                $subject,
                $windowPhrase,
                $datesPhrase,
                'Solicita-se acompanhamento no sentido de garantir que o aluno traz o material necessário às aulas.',
            ),
            RecordFollowupCategory::Lateness => sprintf(
                '%s acumulou %s %s na disciplina de %s, %s, %s. %s',
                self::subjectPhrase('O aluno', $classNumber, $studentName),
                $quantity,
                $count === 1 ? 'falta por atraso' : 'faltas por atraso',
                $subject,
                $windowPhrase,
                $datesPhrase,
                'Solicita-se acompanhamento no sentido de promover o cumprimento do horário de início das aulas.',
            ),
            RecordFollowupCategory::HomeworkNotDone => sprintf(
                '%s %s %s de não realização dos trabalhos de casa %s na disciplina de %s, %s, %s. %s',
                $count === 1 ? 'Foi registada' : 'Foram registadas',
                $quantity,
                $count === 1 ? 'situação' : 'situações',
                self::agentPhrase($classNumber, $studentName),
                $subject,
                $windowPhrase,
                $datesPhrase,
                'Solicita-se acompanhamento no sentido de assegurar a realização das tarefas propostas.',
            ),
        };

        return ['text' => $text, 'missing' => $missing];
    }

    /**
     * «nos dias 03/10, 10/10 (duas ocorrências) e 17/10» — or «no dia 03/10».
     *
     * @param  list<CarbonInterface>  $dates
     */
    public static function datesPhrase(array $dates): string
    {
        $perDay = [];

        $sorted = $dates;
        usort($sorted, fn (CarbonInterface $left, CarbonInterface $right): int => $left->format('Y-m-d') <=> $right->format('Y-m-d'));

        // dd/mm reads unambiguously inside a school year; dates from more than
        // one calendar year carry the year, so 03/10 of two years never merge.
        $years = array_unique(array_map(fn (CarbonInterface $date): string => $date->format('Y'), $sorted));
        $format = count($years) > 1 ? 'd/m/Y' : 'd/m';

        foreach ($sorted as $date) {
            $day = $date->format($format);
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
        }

        $pieces = [];

        foreach ($perDay as $day => $occurrences) {
            $pieces[] = $occurrences === 1
                ? $day
                : sprintf('%s (%s ocorrências)', $day, PortugueseCardinal::feminine($occurrences));
        }

        $prefix = count($pieces) === 1 ? 'no dia' : 'nos dias';

        return $prefix.' '.self::joinList($pieces);
    }

    /**
     * «O aluno n.º 5, Ana Martins,» · «O aluno n.º 5» · «O aluno Ana Martins».
     */
    private static function subjectPhrase(string $opening, ?int $classNumber, ?string $studentName): string
    {
        return match (true) {
            $classNumber !== null && $studentName !== null => "{$opening} n.º {$classNumber}, {$studentName},",
            $classNumber !== null => "{$opening} n.º {$classNumber}",
            $studentName !== null => "{$opening} {$studentName}",
            default => $opening,
        };
    }

    /**
     * «pelo aluno n.º 5, Ana Martins,» · «pelo aluno n.º 5,» · «pelo aluno Ana Martins,» —
     * and, with neither, «pelo aluno» with no stray comma.
     */
    private static function agentPhrase(?int $classNumber, ?string $studentName): string
    {
        return match (true) {
            $classNumber !== null && $studentName !== null => "pelo aluno n.º {$classNumber}, {$studentName},",
            $classNumber !== null => "pelo aluno n.º {$classNumber},",
            $studentName !== null => "pelo aluno {$studentName},",
            default => 'pelo aluno',
        };
    }

    /**
     * @param  list<string>  $items
     */
    private static function joinList(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' e '.$last;
    }
}
