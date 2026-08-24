<?php

namespace Tests\Fixtures\Import;

use Dompdf\Dompdf;

/**
 * Builds a teacher's timetable export, as a real PDF, from nothing.
 *
 * Deliberately NOT a copy of anyone's real horário. The one this importer was
 * designed against is a real teacher's own working file — real school, real
 * turmas, real room numbers — and it stays off this repository entirely. So the
 * fixture is built to order instead, and built to contain the awkward shapes
 * rather than the tidy ones:
 *
 * - a row whose first weekday is empty and whose third is not, which is the
 *   case that makes counting separators produce a confidently wrong weekday;
 * - a cell long enough to wrap onto a second line, which reaches the reader as
 *   two separate runs that have to be put back together;
 * - an entry with only two parts («REE - Sem sala»), and one with three parts
 *   whose first is not a subject code («AE_3C_Mat - …»), neither of which is a
 *   turma and neither of which may ever be imported as one;
 * - the same turma in two consecutive hours, which must stay two blocks.
 *
 * Ana Exemplo teaches at an Agrupamento de Escolas de Exemplo. Neither exists.
 */
class TimetablePdfBuilder
{
    protected string $school = 'Agrupamento de Escolas de Exemplo';

    protected ?string $academicYear = '2025/26';

    protected ?string $teacher = 'Ana Exemplo';

    /** @var list<string> */
    protected array $weekdays = ['2ª FEIRA', '3ª FEIRA', '4ª FEIRA', '5ª FEIRA', '6ª FEIRA'];

    /** @var list<array{hours: string, cells: list<string>}> */
    protected array $rows = [];

    protected bool $withTable = true;

    public static function make(): self
    {
        return new self;
    }

    /**
     * The standard fixture: five weekdays, two turmas, and every awkward shape
     * described on this class.
     */
    public static function example(): self
    {
        return self::make()
            // Two consecutive hours of the same turma — two blocks, never one.
            ->row('08:30 - 09:20', ['MAT - 7º C - S01', 'MAT - 8º A - S02', '', '', ''])
            // Monday filled, TUESDAY EMPTY, Wednesday filled: the row that
            // breaks any reading based on counting separators.
            ->row('09:20 - 10:10', ['MAT - 7º C - S01', '', 'MAT - 8º A - S02', '', ''])
            // Neither of these is a turma.
            ->row('10:30 - 11:20', ['', '', 'REE - Sem sala', '', 'AE_3C_Mat - 7º C - S09'])
            // The long one wraps onto a second line.
            ->row('11:20 - 12:10', ['', '', '', 'MAT - 8º A - PAVILHAO DESPORTIVO', 'MAT - 8º A - S02']);
    }

    /**
     * A perfectly readable PDF that is simply not a timetable.
     */
    public static function withoutATimetable(): self
    {
        return self::make()->noTable();
    }

    /**
     * @param  list<string>  $cells  one per weekday column; '' for a free period
     */
    public function row(string $hours, array $cells): self
    {
        $this->rows[] = ['hours' => $hours, 'cells' => $cells];

        return $this;
    }

    public function academicYear(?string $label): self
    {
        $this->academicYear = $label;

        return $this;
    }

    public function teacher(?string $name): self
    {
        $this->teacher = $name;

        return $this;
    }

    public function noTable(): self
    {
        $this->withTable = false;

        return $this;
    }

    public function bytes(): string
    {
        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($this->html(), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    protected function html(): string
    {
        $head = '<html><head><meta charset="utf-8"><style>'
            .'body { font-family: DejaVu Sans, sans-serif; font-size: 8px; }'
            .'table { width: 100%; border-collapse: collapse; table-layout: fixed; }'
            .'td, th { border: 1px solid #333; padding: 2px; width: 16.6%; }'
            .'</style></head><body>';

        $title = '<p>'.e($this->school).'</p><p>HORÁRIO PROFESSOR</p>'
            .($this->academicYear === null ? '' : '<p>'.e($this->academicYear).'</p>')
            .($this->teacher === null ? '' : '<p>'.e($this->teacher).'</p>');

        $footer = '<p>Rua de Exemplo - 0000-000 EXEMPLO</p>';

        if (! $this->withTable) {
            return $head.$title.'<p>Este documento não contém qualquer horário.</p>'.$footer.'</body></html>';
        }

        $header = '<tr><th>Horas</th>';

        foreach ($this->weekdays as $weekday) {
            $header .= '<th>'.e($weekday).'</th>';
        }

        $header .= '</tr>';

        $body = '';

        foreach ($this->rows as $row) {
            $body .= '<tr><td>'.e($row['hours']).'</td>';

            foreach ($row['cells'] as $cell) {
                $body .= '<td>'.e($cell).'</td>';
            }

            $body .= '</tr>';
        }

        return $head.$title.'<table>'.$header.$body.'</table>'.$footer.'</body></html>';
    }
}
