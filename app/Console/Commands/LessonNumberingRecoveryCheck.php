<?php

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Services\Lessons\LessonNumbering;
use App\Support\Lessons\LessonNumberingFreeze;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Que recuperação se aplica à 0.169.0 (números de lição indicados pelo
 * professor), dito a partir dos próprios dados (docs/lesson-numbering-recovery.md).
 *
 * O código anterior à 0.169.0 ignora as colunas `lesson_number_end` e
 * `lesson_number_manual`: volta a derivar as aulas abertas pela posição (e
 * escreve por cima dos números manuais), e as lecionadas manuais fora da
 * posição fazem a guarda antiga recusar operações. Enquanto não houver nenhuma
 * aula com número manual nem intervalo, um rollback só de código é uma
 * recuperação completa (cenário a); a partir do primeiro, não é, e a numeração
 * é contida e corrigida para a frente (cenário b).
 *
 * TRÊS MODOS:
 *  - sem opções: só de leitura. Conta por organização e sai 0 (cenário a) ou 1
 *    (cenário b). Com `--export=<caminho>` grava também um JSON com as aulas em
 *    causa — o ponto de partida de uma reposição;
 *  - `--restore=<caminho>`: lê esse JSON e relata o que mudaria;
 *  - `--restore=<caminho> --apply`: repõe, numa transação, o triplo
 *    (`lesson_number`, `lesson_number_end`, `lesson_number_manual`) das aulas que
 *    ainda existem e renumera as turmas tocadas pelo caminho automático.
 *
 * A REPOSIÇÃO NÃO É RECUSADA COM A NUMERAÇÃO CONGELADA: repor é precisamente o
 * que se quer fazer numa recuperação, e escreve o triplo guardado, não deriva.
 * (A renumeração a seguir, essa, respeita o congelamento: só escreve aulas sem
 * número.)
 *
 * SÓ IDS, NÚMEROS E CONTAGENS: nunca nomes de alunos nem texto de sumário. O
 * JSON exportado leva ulids de aula e de turma, o id da organização e os
 * números.
 */
class LessonNumberingRecoveryCheck extends Command
{
    protected $signature = 'lapis:lesson-numbering-recovery-check
                            {--export= : Write the manual numbers and ranges to this JSON file (outside the deploy package).}
                            {--restore= : Read a file written by --export and report what restoring it would change.}
                            {--apply : With --restore, write it back inside one transaction.}';

    protected $description = 'Say whether a code-only rollback of 0.169.0 still keeps the lesson numbers set by teachers; export and restore them (read-only unless --restore --apply)';

    public function handle(LessonNumbering $numbering, CurrentOrganization $currentOrganization): int
    {
        $restore = $this->option('restore');

        if (is_string($restore) && $restore !== '') {
            return $this->restore($restore, (bool) $this->option('apply'), $numbering, $currentOrganization);
        }

        return $this->check($currentOrganization);
    }

    private function check(CurrentOrganization $currentOrganization): int
    {
        $manual = 0;
        $ranges = 0;
        $exported = [];

        $this->line('Numeração das lições — recuperação da 0.169.0');

        foreach (Organization::query()->withoutGlobalScope('organization')->orderBy('id')->get() as $organization) {
            $currentOrganization->runFor($organization, function () use ($organization, &$manual, &$ranges, &$exported): void {
                $organizationManual = Lesson::query()->where('lesson_number_manual', true)->count();
                $organizationRanges = Lesson::query()->whereNotNull('lesson_number_end')->count();

                if ($organizationManual === 0 && $organizationRanges === 0) {
                    return;
                }

                $manual += $organizationManual;
                $ranges += $organizationRanges;
                $this->line("  organização #{$organization->id}: números indicados: {$organizationManual}; intervalos: {$organizationRanges}");

                Lesson::query()
                    ->where(fn ($query) => $query->where('lesson_number_manual', true)->orWhereNotNull('lesson_number_end'))
                    ->with('schoolClass:id,ulid')
                    ->orderBy('id')
                    ->get()
                    ->each(function (Lesson $lesson) use ($organization, &$exported): void {
                        $exported[] = [
                            'lesson_ulid' => $lesson->ulid,
                            'class_ulid' => $lesson->schoolClass->ulid,
                            'organization_id' => (int) $organization->id,
                            'lesson_number' => $lesson->lesson_number,
                            'lesson_number_end' => $lesson->lesson_number_end,
                            'lesson_number_manual' => (bool) $lesson->lesson_number_manual,
                        ];
                    });
            });
        }

        $this->line("  aulas com número indicado pelo professor: {$manual}");
        $this->line("  aulas com intervalo de lições: {$ranges}");
        $this->line('  numeração congelada: '.(LessonNumberingFreeze::frozen() ? 'ligada (nenhum número que já exista muda)' : 'desligada'));

        $export = $this->option('export');

        if (is_string($export) && $export !== '') {
            if (! $this->writeExport($export, $exported)) {
                return self::FAILURE;
            }
        }

        if ($manual === 0 && $ranges === 0) {
            $this->info('Cenário a): nenhum número indicado nem intervalo. O rollback só de código para a 0.168.0 é uma recuperação completa.');

            return self::SUCCESS;
        }

        $this->warn('Cenário b): há números indicados pelo professor. Não repor código abaixo da 0.169.0 — congelar a numeração (LAPIS_LESSON_NUMBERING_FROZEN=true) e corrigir para a frente numa 0.169.x.');

        return self::FAILURE;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeExport(string $path, array $rows): bool
    {
        $json = json_encode(['version' => 1, 'lessons' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (@file_put_contents($path, $json) === false) {
            $this->error("Não foi possível escrever {$path}.");

            return false;
        }

        @chmod($path, 0600);
        $this->line('  exportadas '.count($rows)." aula(s) para {$path} (guardar fora do pacote de deploy).");

        return true;
    }

    private function restore(string $path, bool $apply, LessonNumbering $numbering, CurrentOrganization $currentOrganization): int
    {
        $rows = $this->readExport($path);

        if ($rows === null) {
            return self::FAILURE;
        }

        $this->line($apply
            ? 'A REPOR os números indicados.'
            : 'Simulação. Nada é escrito. Use --apply para repor.');

        $byOrganization = [];

        foreach ($rows as $row) {
            $byOrganization[$row['organization_id']][] = $row;
        }

        ksort($byOrganization);
        $totals = ['restored' => 0, 'unchanged' => 0, 'missing' => 0, 'classes' => 0];

        $run = function () use ($byOrganization, $apply, $numbering, $currentOrganization, &$totals): void {
            foreach ($byOrganization as $organizationId => $organizationRows) {
                $organization = Organization::query()->withoutGlobalScope('organization')->find($organizationId);

                if ($organization === null) {
                    $totals['missing'] += count($organizationRows);
                    $this->warn("  organização #{$organizationId}: já não existe; ".count($organizationRows).' aula(s) por repor.');

                    continue;
                }

                $currentOrganization->runFor($organization, function () use ($organizationRows, $apply, $numbering, &$totals): void {
                    $touchedClasses = [];

                    foreach ($organizationRows as $row) {
                        $lesson = Lesson::query()->where('ulid', $row['lesson_ulid'])->with('schoolClass:id,ulid')->first();

                        if ($lesson === null || $lesson->schoolClass->ulid !== $row['class_ulid']) {
                            $totals['missing']++;

                            continue;
                        }

                        if ($lesson->lesson_number === $row['lesson_number']
                            && $lesson->lesson_number_end === $row['lesson_number_end']
                            && (bool) $lesson->lesson_number_manual === $row['lesson_number_manual']) {
                            $totals['unchanged']++;

                            continue;
                        }

                        $this->line(sprintf(
                            '  aula #%d (turma #%d): Lição %s -> Lição %s%s',
                            $lesson->id,
                            $lesson->class_id,
                            Lesson::formatLessonNumber($lesson->lesson_number, $lesson->lesson_number_end) ?? '—',
                            Lesson::formatLessonNumber($row['lesson_number'], $row['lesson_number_end']) ?? '—',
                            $row['lesson_number_manual'] ? ' (indicado)' : '',
                        ));
                        $totals['restored']++;

                        if ($apply) {
                            DB::table('lessons')->where('id', $lesson->id)->update([
                                'lesson_number' => $row['lesson_number'],
                                'lesson_number_end' => $row['lesson_number_end'],
                                'lesson_number_manual' => $row['lesson_number_manual'],
                            ]);
                            $touchedClasses[(int) $lesson->class_id] = true;
                        }
                    }

                    // Repostos os números fixos, as aulas livres das mesmas turmas
                    // voltam a ser derivadas à volta deles (o caminho automático,
                    // que nunca falha e nunca mexe nos fixos).
                    foreach (array_keys($touchedClasses) as $classId) {
                        SchoolClass::query()->whereKey($classId)->lockForUpdate()->first();
                        $numbering->numberMaterializedLessons($classId);
                        $totals['classes']++;
                    }
                });
            }
        };

        if ($apply) {
            DB::transaction($run);
        } else {
            $run();
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d aula(s); sem alteração: %d; já não existem: %d; turmas renumeradas: %d.',
            $apply ? 'Repostas' : 'A repor',
            $totals['restored'],
            $totals['unchanged'],
            $totals['missing'],
            $totals['classes'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<array{lesson_ulid: string, class_ulid: string, organization_id: int, lesson_number: int|null, lesson_number_end: int|null, lesson_number_manual: bool}>|null
     */
    private function readExport(string $path): ?array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded) || ! is_array($decoded['lessons'] ?? null)) {
            $this->error("{$path} não é um ficheiro escrito por --export.");

            return null;
        }

        $rows = [];

        foreach ($decoded['lessons'] as $index => $row) {
            $start = is_array($row) ? ($row['lesson_number'] ?? null) : null;
            $end = is_array($row) ? ($row['lesson_number_end'] ?? null) : null;

            if (! is_array($row)
                || ! is_string($row['lesson_ulid'] ?? null) || ! is_string($row['class_ulid'] ?? null)
                || ! is_int($row['organization_id'] ?? null)
                || ($start !== null && ! is_int($start)) || ($end !== null && ! is_int($end))
                || ($end !== null && ($start === null || $end <= $start))
                || ! is_bool($row['lesson_number_manual'] ?? null)) {
                $this->error('Linha '.($index + 1)." de {$path} inválida. Nada foi escrito.");

                return null;
            }

            $rows[] = [
                'lesson_ulid' => $row['lesson_ulid'],
                'class_ulid' => $row['class_ulid'],
                'organization_id' => $row['organization_id'],
                'lesson_number' => $start,
                'lesson_number_end' => $end,
                'lesson_number_manual' => $row['lesson_number_manual'],
            ];
        }

        return $rows;
    }
}
