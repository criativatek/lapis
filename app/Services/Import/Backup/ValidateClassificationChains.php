<?php

namespace App\Services\Import\Backup;

use App\Models\ClassificationStatus;

/**
 * Schema v20 — the chains of rectifications inside `classifications`
 * (docs/backup-schema.md, «Retificações de classificações»).
 *
 * A rectified decision is not edited: a NEW row replaces it, the old one stays
 * as history, and the two point at each other (`superseded_by_ulid` one way,
 * `rectifies_ulid` the other). Each row has already been checked on its own;
 * this judges the chains as a whole, because a pointer is only as good as the
 * row it points at.
 *
 * THE UNIT OF REFUSAL IS THE CELL, NOT THE ROW. A chain restored without one of
 * its links is a decision history with a hole in it, and a cell restored without
 * its live row is a student with no grade — so a row that fails takes its whole
 * cell (student × period × scope) with it, each row named in «Pontos a rever».
 * A chain is never partly restored.
 *
 * What is refused, which is exactly what the table would refuse (and what no
 * hand-written backup should be able to make it): a row that is superseded
 * without a successor, or has one without being superseded; a pointer to a row
 * the file does not carry, to itself, or into another cell; a pair that does not
 * agree with each other; a cycle; more than one live row in a cell; and a
 * rectification that is not published (the service only ever writes it so).
 * A pair that agrees is also what makes the chain LINEAR: with one successor
 * field and one predecessor field per row, two rows cannot claim the same
 * predecessor or successor without one of them disagreeing.
 */
final class ValidateClassificationChains
{
    /**
     * @param  list<array<string, mixed>>  $rows  the classifications, each valid on its own
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return list<array<string, mixed>> the rows that may be restored
     */
    public function validate(array $rows, array &$rowIssues): array
    {
        $rows = $this->withoutDuplicates($rows, $rowIssues);

        /** @var array<string, array<string, mixed>> $byUlid */
        $byUlid = [];

        foreach ($rows as $row) {
            $byUlid[(string) $row['ulid']] = $row;
        }

        /** @var array<string, string> $bad ulid => why */
        $bad = [];

        foreach ($byUlid as $ulid => $row) {
            $reason = $this->ownProblem($ulid, $row, $byUlid);

            if ($reason !== null) {
                $bad[$ulid] = $reason;
            }
        }

        foreach ($this->cyclic($byUlid, $bad) as $ulid) {
            $bad[$ulid] = 'A cadeia de retificações desta classificação dá a volta a si própria.';
        }

        foreach ($this->cellsWithSeveralLiveRows($byUlid) as $ulid) {
            $bad[$ulid] ??= 'A mesma célula (aluno, período e âmbito) tem mais do que uma classificação em vigor.';
        }

        $bad = $this->spreadToTheCell($byUlid, $bad);

        foreach ($bad as $ulid => $reason) {
            $rowIssues[] = ['domain' => 'classifications', 'ulid' => $ulid, 'reason' => $this->t($reason)];
        }

        return array_values(array_filter($rows, fn (array $row): bool => ! isset($bad[(string) $row['ulid']])));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{domain: string, ulid: string|null, reason: string}>  $rowIssues
     * @return list<array<string, mixed>>
     */
    private function withoutDuplicates(array $rows, array &$rowIssues): array
    {
        $seen = [];
        $kept = [];

        foreach ($rows as $row) {
            $ulid = (string) $row['ulid'];

            if (isset($seen[$ulid])) {
                $rowIssues[] = ['domain' => 'classifications', 'ulid' => $ulid, 'reason' => $this->t('Linha duplicada neste ficheiro.')];

                continue;
            }

            $seen[$ulid] = true;
            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * What is wrong with this row on its own terms and with the two rows it
     * points at — null when nothing is.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, mixed>>  $byUlid
     */
    private function ownProblem(string $ulid, array $row, array $byUlid): ?string
    {
        $next = $row['superseded_by_ulid'];
        $previous = $row['rectifies_ulid'];

        if (($row['status'] === ClassificationStatus::Superseded->value) !== ($next !== null)) {
            return 'Uma classificação está substituída se, e só se, tem uma classificação que a substitui.';
        }

        if ($previous !== null && ! in_array($row['status'], [ClassificationStatus::Published->value, ClassificationStatus::Superseded->value], true)) {
            return 'Uma retificação é sempre uma classificação publicada.';
        }

        if ($next === $ulid || $previous === $ulid) {
            return 'Uma classificação não pode substituir nem retificar-se a si própria.';
        }

        if ($next !== null) {
            $successor = $byUlid[$next] ?? null;

            if ($successor === null) {
                return 'A classificação que substitui esta não consta deste ficheiro.';
            }

            if ($successor['rectifies_ulid'] !== $ulid) {
                return 'A classificação que substitui esta não diz que a retifica.';
            }

            if ($this->cell($successor) !== $this->cell($row)) {
                return 'A classificação que substitui esta é de outro aluno, período ou âmbito.';
            }
        }

        if ($previous !== null) {
            $predecessor = $byUlid[$previous] ?? null;

            if ($predecessor === null) {
                return 'A classificação retificada não consta deste ficheiro.';
            }

            if ($predecessor['superseded_by_ulid'] !== $ulid) {
                return 'A classificação retificada não diz que foi substituída por esta.';
            }

            if ($this->cell($predecessor) !== $this->cell($row)) {
                return 'A classificação retificada é de outro aluno, período ou âmbito.';
            }
        }

        return null;
    }

    /**
     * The rows that lie on a loop of successors. Every row of a loop is
     * superseded and agrees with its neighbours, so nothing else catches it —
     * and a restore that walked it would never reach a live row.
     *
     * @param  array<string, array<string, mixed>>  $byUlid
     * @param  array<string, string>  $bad
     * @return list<string>
     */
    private function cyclic(array $byUlid, array $bad): array
    {
        $found = [];

        foreach (array_keys($byUlid) as $start) {
            if (isset($bad[$start])) {
                continue;
            }

            $seen = [$start => true];
            $at = $byUlid[$start]['superseded_by_ulid'];

            while ($at !== null && isset($byUlid[$at])) {
                if (isset($seen[$at])) {
                    $found[] = $start;

                    break;
                }

                $seen[$at] = true;
                $at = $byUlid[$at]['superseded_by_ulid'];
            }
        }

        return $found;
    }

    /**
     * Every row of a cell that holds more than one live one.
     *
     * @param  array<string, array<string, mixed>>  $byUlid
     * @return list<string>
     */
    private function cellsWithSeveralLiveRows(array $byUlid): array
    {
        $live = [];

        foreach ($byUlid as $ulid => $row) {
            if ($row['status'] !== ClassificationStatus::Superseded->value) {
                $live[$this->cell($row)][] = $ulid;
            }
        }

        $found = [];

        foreach ($live as $ulids) {
            if (count($ulids) > 1) {
                array_push($found, ...$ulids);
            }
        }

        return $found;
    }

    /**
     * A refused row takes its whole cell with it.
     *
     * @param  array<string, array<string, mixed>>  $byUlid
     * @param  array<string, string>  $bad
     * @return array<string, string>
     */
    private function spreadToTheCell(array $byUlid, array $bad): array
    {
        $badCells = [];

        foreach (array_keys($bad) as $ulid) {
            $badCells[$this->cell($byUlid[$ulid])] = true;
        }

        // The pointers never leave a cell (a pointer into another one is itself
        // refused), so the cell is all the spreading there is.
        foreach ($byUlid as $ulid => $row) {
            if (! isset($bad[$ulid]) && isset($badCells[$this->cell($row)])) {
                $bad[$ulid] = 'Outra classificação desta célula (aluno, período e âmbito) não pôde ser restaurada, e a cadeia de retificações não se restaura por partes.';
            }
        }

        return $bad;
    }

    /** @param array<string, mixed> $row */
    private function cell(array $row): string
    {
        return $row['enrollment_ulid'].'|'.($row['academic_period_ulid'] ?? '').'|'.$row['scope'];
    }

    private function t(string $key): string
    {
        return (string) __($key);
    }
}
