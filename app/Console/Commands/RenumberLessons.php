<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Services\Lessons\LessonNumbering;
use App\Services\Lessons\SplitLessonKeyInference;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A correção histórica da 0.145.2: numerar as aulas por TURMA, com as aulas de
 * T1 e T2 da mesma lição a partilhar o número.
 *
 * Deliberadamente um comando e não uma migração, pelo mesmo motivo que
 * RepairOverlappingSubscriptions: inspeciona-se antes de tocar.
 *
 * TRÊS CASOS POR TURMA (SplitLessonKeyInference):
 *  - SEM GRUPO / JÁ LIGADO: renumera;
 *  - INEQUÍVOCO: grava o vínculo dos tempos, liga as aulas e renumera;
 *  - AMBÍGUO: NÃO escreve nada nessa turma — nem vínculos nem números — e
 *    reporta. Um vínculo errado seria pior do que nenhum; o professor liga os
 *    tempos no horário e o comando corre outra vez.
 *
 * É A ÚNICA VIA QUE RENUMERA AULAS LECIONADAS. Idempotente: vínculos gravados
 * não mudam, e uma segunda execução não encontra nada. Só escreve
 * `recurring_lesson_slots.split_lesson_key`, `lessons.lesson_unit_key` e
 * `lessons.lesson_number`. Imprime ids, datas e números — nunca nomes.
 */
class RenumberLessons extends Command
{
    protected $signature = 'lapis:renumber-lessons
                            {--apply : Write the links and numbers. Without it the command only reports.}
                            {--class= : Only this class id.}';

    protected $description = 'Report — and with --apply, write — class-wide lesson numbers with explicit T1/T2 links';

    private const array LABELS = [
        SplitLessonKeyInference::NoGroups => 'SEM GRUPO',
        SplitLessonKeyInference::AlreadyLinked => 'JÁ LIGADO',
        SplitLessonKeyInference::Unambiguous => 'EMPARELHAMENTO INEQUÍVOCO',
        SplitLessonKeyInference::Ambiguous => 'AMBÍGUO',
    ];

    public function handle(
        LessonNumbering $numbering,
        SplitLessonKeyInference $inference,
        CurrentOrganization $currentOrganization,
    ): int {
        $apply = (bool) $this->option('apply');
        $onlyClass = $this->option('class');

        $this->line($apply
            ? 'A APLICAR a renumeração.'
            : 'Simulação. Nada é escrito. Use --apply para renumerar.');
        $this->newLine();

        $totals = ['classes' => 0, 'lessons' => 0, 'ambiguous' => 0];

        foreach (Organization::query()->withoutGlobalScope('organization')->orderBy('id')->get() as $organization) {
            $currentOrganization->runFor($organization, function () use ($apply, $inference, $numbering, $onlyClass, &$totals): void {
                $classes = SchoolClass::query()->orderBy('id');

                if (is_string($onlyClass) && $onlyClass !== '') {
                    $classes->whereKey((int) $onlyClass);
                }

                foreach ($classes->get(['id', 'label']) as $class) {
                    DB::transaction(function () use ($apply, $class, $inference, $numbering, &$totals): void {
                        SchoolClass::query()->whereKey($class->id)->lockForUpdate()->first();

                        $inferred = $inference->infer((int) $class->id);

                        if ($inferred['status'] === SplitLessonKeyInference::Ambiguous) {
                            $totals['ambiguous']++;
                            $this->warn(sprintf('Turma #%d (%s): AMBÍGUO — %s. Nada escrito; ligar os tempos no horário.', $class->id, $class->label, $inferred['reason']));

                            return;
                        }

                        if ($apply && $inferred['keys'] !== []) {
                            foreach ($inferred['keys'] as $slotId => $key) {
                                RecurringLessonSlot::query()->whereKey($slotId)->whereNull('split_lesson_key')->update(['split_lesson_key' => $key]);
                            }
                        }

                        $result = $numbering->rebuild((int) $class->id, write: $apply, slotKeyOverrides: $apply ? [] : $inferred['keys']);

                        if ($result['numbers'] === [] && $result['links'] === [] && $inferred['keys'] === []) {
                            return;
                        }

                        $totals['classes']++;
                        $totals['lessons'] += count($result['numbers']);
                        $this->line(sprintf(
                            'Turma #%d (%s): %s — %d tempo(s) a ligar, %d aula(s) a ligar, %d aula(s) a renumerar.',
                            $class->id,
                            $class->label,
                            self::LABELS[$inferred['status']],
                            count($inferred['keys']),
                            count($result['links']),
                            count($result['numbers']),
                        ));

                        foreach ($result['numbers'] as $lessonId => $change) {
                            $this->line(sprintf(
                                '  aula #%d  %s  %s  Lição %s -> Lição %d',
                                $lessonId,
                                $change['lesson']->starts_at->setTimezone('Europe/Lisbon')->format('Y-m-d H:i'),
                                $change['lesson']->class_group_id === null ? 'turma' : 'grupo #'.$change['lesson']->class_group_id,
                                $change['from'] === null ? '—' : (string) $change['from'],
                                $change['to'],
                            ));
                        }
                    });
                }
            });
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d turma(s), %d aula(s). Ambíguas (não tocadas): %d.',
            $apply ? 'Renumeradas' : 'A renumerar',
            $totals['classes'],
            $totals['lessons'],
            $totals['ambiguous'],
        ));

        return self::SUCCESS;
    }
}
