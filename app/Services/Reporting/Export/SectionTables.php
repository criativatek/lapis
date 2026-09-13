<?php

namespace App\Services\Reporting\Export;

use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\Narrative\Grade;
use App\Services\Reporting\Narrative\Phrase;

/**
 * A section's structured payload, turned into tables a renderer can lay out.
 *
 * THE PHP TWIN OF ReportSectionData.vue, and deliberately so: the preview on
 * screen and the exported document must show the same table for the same
 * section, or the preview stops being a preview (§51). Both read the same
 * `data` payload and neither computes anything — the figures were decided by
 * the composer, which got them from the source, which got them from the read
 * model.
 *
 * NOT EVERY SECTION HAS A TABLE. A paragraph followed by a table restating the
 * same three numbers is padding, so only genuinely tabular content gets one.
 *
 * WHAT IS ABSENT FROM THE DATA STAYS ABSENT FROM THE TABLE. The chronology's
 * student column exists only when the teacher authorised identification, and
 * then the names are already in the payload — this never reaches for a name
 * that the source declined to put there (§28).
 */
class SectionTables
{
    /**
     * @return list<array{caption: string|null, headers: list<string>, rows: list<list<string>>}>
     */
    public static function for(string $key, mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        return match (SectionKey::tryFrom($key)) {
            SectionKey::ClassDistribution => self::distribution($data),
            SectionKey::DomainResults => self::domains($data),
            SectionKey::ClassRecords, SectionKey::StudentRecords, SectionKey::RecordsDistribution => self::records($data),
            SectionKey::RecordsTimeline => self::timeline($data),
            SectionKey::ClassAttendance => self::classAttendance($data),
            SectionKey::StudentAttendance => self::studentAttendance($data),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{caption: string|null, headers: list<string>, rows: list<list<string>>}>
     */
    protected static function classAttendance(array $data): array
    {
        $rows = [];

        foreach (self::listOf($data, 'rows') as $row) {
            $rows[] = [
                $row['class_number'] === null ? '—' : (string) $row['class_number'],
                (string) ($row['name'] ?? '—'),
                (string) ($row['present'] ?? 0),
                (string) ($row['absent'] ?? 0),
                (string) ($row['not_recorded'] ?? 0),
            ];
        }

        return $rows === [] ? [] : [[
            'caption' => 'Assiduidade por aluno',
            'headers' => ['N.º', 'Nome', 'Presenças', 'Faltas', 'Sem registo'],
            'rows' => $rows,
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{caption: string|null, headers: list<string>, rows: list<list<string>>}>
     */
    protected static function studentAttendance(array $data): array
    {
        $rows = [];

        // Do mais recente para o mais antigo — a mesma ordem do cartão de
        // Evolução do Aluno, para que a mesma informação nunca se leia de
        // formas diferentes consoante o ecrã.
        foreach (array_reverse(self::listOf($data, 'rows')) as $row) {
            $status = (string) ($row['status'] ?? '');

            $rows[] = [
                self::readableDate((string) ($row['date'] ?? '')),
                (string) ($row['subject'] ?? '—'),
                (string) ($row['context_label'] ?? '—'),
                $row['lesson_number'] === null ? '—' : (string) $row['lesson_number'],
                match ($status) {
                    'present' => 'Presente',
                    'absent' => 'Falta',
                    default => 'Assiduidade não registada',
                },
            ];
        }

        return $rows === [] ? [] : [[
            'caption' => 'Assiduidade por aula',
            'headers' => ['Data', 'Disciplina', 'Turma/contexto', 'Aula n.º', 'Estado'],
            'rows' => $rows,
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{caption: string|null, headers: list<string>, rows: list<list<string>>}>
     */
    protected static function distribution(array $data): array
    {
        $rows = [];

        foreach (self::listOf($data, 'rows') as $row) {
            $rows[] = [
                // THE CLASSIFICATION LEADS, the mention follows it (§6).
                Grade::cell($row)
                    .(($row['outside_scale'] ?? false) === true ? ' (fora da escala atual)' : ''),
                (string) ($row['count'] ?? 0),
                Phrase::percentage($row['percentage'] ?? null) ?? '—',
            ];
        }

        return $rows === [] ? [] : [[
            'caption' => 'Classificações atribuídas',
            'headers' => ['Classificação', 'Alunos', '%'],
            'rows' => $rows,
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{caption: string|null, headers: list<string>, rows: list<list<string>>}>
     */
    protected static function domains(array $data): array
    {
        $rows = [];

        foreach (self::listOf($data, 'domains') as $row) {
            $placed = (int) ($row['placed'] ?? 0);
            $without = (int) ($row['students_without_result'] ?? 0);

            $rows[] = [
                (string) ($row['label'] ?? '—'),
                Phrase::percentage($row['value'] ?? null) ?? '—',
                $placed > 0 ? ($row['succeeded'] ?? 0).' / '.$placed : '—',
                $without > 0 ? (string) $without : '—',
            ];
        }

        return $rows === [] ? [] : [[
            'caption' => 'Resultados por domínio',
            'headers' => ['Domínio', 'Média', 'Positivas', 'Sem resultado'],
            'rows' => $rows,
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{caption: string|null, headers: list<string>, rows: list<list<string>>}>
     */
    protected static function records(array $data): array
    {
        $rows = [];

        foreach (self::listOf($data, 'kinds') as $row) {
            $students = (int) ($row['students_involved'] ?? 0);

            $rows[] = [
                (string) ($row['label'] ?? '—'),
                (string) ($row['records'] ?? 0),
                $students > 0 ? (string) $students : '—',
            ];
        }

        return $rows === [] ? [] : [[
            'caption' => 'Registos por tipo',
            // The second unit, in the header. A record is not a student (§71).
            'headers' => ['Tipo de registo', 'Registos', 'Alunos envolvidos'],
            'rows' => $rows,
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{caption: string|null, headers: list<string>, rows: list<list<string>>}>
     */
    protected static function timeline(array $data): array
    {
        $entries = self::listOf($data, 'rows');

        if ($entries === []) {
            return [];
        }

        // Present only when the source put names in — which it does only when
        // the teacher authorised identification.
        $named = false;

        foreach ($entries as $entry) {
            if (array_key_exists('student', $entry)) {
                $named = true;

                break;
            }
        }

        $rows = [];

        foreach ($entries as $entry) {
            $row = [self::readableDate((string) ($entry['occurred_on'] ?? ''))];

            if ($named) {
                $row[] = (string) ($entry['student'] ?? '—');
            }

            $row[] = (string) ($entry['kind_label'] ?? '—');
            $row[] = (string) ($entry['description'] ?? '');

            $rows[] = $row;
        }

        return [[
            'caption' => 'Cronologia',
            'headers' => $named
                ? ['Data', 'Aluno', 'Tipo', 'Descrição']
                : ['Data', 'Tipo', 'Descrição'],
            'rows' => $rows,
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    protected static function listOf(array $data, string $key): array
    {
        $rows = $data[$key] ?? null;

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    protected static function readableDate(string $date): string
    {
        $parts = explode('-', $date);

        return count($parts) === 3 ? $parts[2].'/'.$parts[1].'/'.$parts[0] : $date;
    }
}
