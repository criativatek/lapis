<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SchoolClass;
use App\Services\Lessons\LessonNumbering;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A correção histórica da 0.145.2: numerar as aulas por TURMA, com T1 e T2 da
 * mesma lição a partilhar o número (LessonNumbering::rebuild).
 *
 * Deliberadamente um comando e não uma migração, pelo mesmo motivo que
 * RepairOverlappingSubscriptions: é uma condição de dados que se inspeciona
 * antes de tocar e se verifica depois, e tem de continuar executável.
 *
 * É A ÚNICA VIA QUE RENUMERA AULAS LECIONADAS. Fá-lo porque a numeração por
 * (turma, grupo) estava errada desde a origem, e o utilizador autorizou a
 * correção. No funcionamento normal o histórico lecionado continua estável.
 *
 * IDEMPOTENTE: os números são uma função da cronologia e dos grupos, pelo que
 * uma segunda execução com --apply não encontra nada para mudar. Só escreve
 * `lessons.lesson_number`.
 */
class RenumberLessons extends Command
{
    protected $signature = 'lapis:renumber-lessons
                            {--apply : Write the new numbers. Without it the command only reports.}';

    protected $description = 'Report — and with --apply, rewrite — lesson numbers so that split groups (T1/T2) share the class sequence';

    public function handle(LessonNumbering $numbering, CurrentOrganization $currentOrganization): int
    {
        $apply = (bool) $this->option('apply');

        $this->line($apply
            ? 'A APLICAR a renumeração.'
            : 'Simulação. Nada é escrito. Use --apply para renumerar.');
        $this->newLine();

        $classesChanged = 0;
        $lessonsChanged = 0;

        foreach (Organization::query()->withoutGlobalScope('organization')->orderBy('id')->get() as $organization) {
            $currentOrganization->runFor($organization, function () use ($apply, $numbering, &$classesChanged, &$lessonsChanged): void {
                foreach (SchoolClass::query()->orderBy('id')->pluck('id') as $classId) {
                    $changes = DB::transaction(function () use ($apply, $classId, $numbering): array {
                        // O mesmo bloqueio de turma que a materialização e a
                        // inserção usam.
                        SchoolClass::query()->whereKey($classId)->lockForUpdate()->first();

                        return $numbering->rebuild((int) $classId, write: $apply);
                    });

                    if ($changes === []) {
                        continue;
                    }

                    $classesChanged++;
                    $lessonsChanged += count($changes);
                    $this->line(sprintf('Turma #%d: %d aula(s) a renumerar.', $classId, count($changes)));

                    foreach ($changes as $lessonId => $change) {
                        $this->line(sprintf(
                            '  aula #%d  %s  Lição %s → Lição %d',
                            $lessonId,
                            $change['lesson']->starts_at->setTimezone('Europe/Lisbon')->format('Y-m-d H:i'),
                            $change['from'] === null ? '—' : (string) $change['from'],
                            $change['to'],
                        ));
                    }
                }
            });
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d turma(s), %d aula(s).',
            $apply ? 'Renumeradas' : 'A renumerar',
            $classesChanged,
            $lessonsChanged,
        ));

        return self::SUCCESS;
    }
}
