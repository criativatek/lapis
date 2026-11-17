<?php

namespace App\Services\Assessment\Export;

use App\Models\AcademicPeriod;
use App\Models\SchoolClass;
use App\Services\Assessment\AccumulatedBreakdown;
use App\Services\Assessment\BuildClassSynopsis;

/**
 * O Quadro Síntese de uma turma como livro de Excel — o único sítio que o compõe.
 *
 * FONTE ÚNICA. `ClassSynopsisExportController` e o «Pacote de reunião» leem daqui os mesmos bytes: a
 * mesma leitura que o ecrã (`BuildClassSynopsis`), a conta do desempenho acumulado para a turma
 * inteira, o mesmo contexto. Quem chama trata do que não é o ficheiro: a autorização, o rasto de
 * auditoria e a resposta.
 */
class ClassSynopsisExport
{
    public function __construct(
        protected BuildClassSynopsis $synopsis,
        protected AccumulatedBreakdown $breakdown,
        protected ClassSynopsisXlsxWriter $xlsx,
    ) {}

    /**
     * @return array{bytes: string, students: int, moments: int, elements: int} the file and the counts the trail records
     */
    public function build(SchoolClass $class): array
    {
        $synopsis = $this->synopsis->for($class);
        $scale = $class->profileVersion?->scale()->with('levels')->first();

        // A CONTA DO DESEMPENHO ACUMULADO, para a turma inteira e de uma vez. O
        // ecrã pergunta-a célula a célula, quando alguém a quer; o ficheiro
        // leva-a toda, porque quem o abre uma semana depois não tem onde clicar.
        // A ÚLTIMA UNIDADE DO ANO, por uma consulta PRÓPRIA e não pela relação.
        // `AcademicYear::periods()` traz um `orderBy('sequence')` seu, e um
        // `orderByDesc` acrescentado fica em segundo lugar: a consulta sairia
        // pela ordem original e isto devolveria o PRIMEIRO semestre a chamar-lhe
        // o fim do ano — a mesma armadilha que já está documentada em
        // `TwoReadingsOfTheYearTest`.
        $lastPeriod = AcademicPeriod::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->orderByDesc('sequence')
            ->first();
        $context = [
            'class_label' => (string) $class->label,
            'subject' => (string) $class->subject->name,
            'academic_year' => (string) $class->academicYear->label,
            'scale_name' => $scale?->name,
            'scale_levels' => $scale === null ? [] : $scale->levels
                ->sortBy('sequence')
                ->map(fn ($level): array => [
                    'code' => (string) $level->code,
                    'label' => (string) $level->label,
                    'is_negative' => (bool) $level->is_negative,
                ])->values()->all(),
            'exported_on' => now()->format('d/m/Y H:i'),
            'accumulated_period_label' => $lastPeriod === null ? null : (string) $lastPeriod->label,
        ];

        $accumulated = $lastPeriod === null
            ? []
            : $this->breakdown->forClassByDomain($class, $lastPeriod);

        return [
            'bytes' => $this->xlsx->write($synopsis, $context, $accumulated),
            'students' => count($synopsis['students']),
            'moments' => count($synopsis['moments']),
            'elements' => count($synopsis['elements']),
        ];
    }

    public function contentType(): string
    {
        return $this->xlsx->contentType();
    }
}
