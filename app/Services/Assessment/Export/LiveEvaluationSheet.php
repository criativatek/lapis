<?php

namespace App\Services\Assessment\Export;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Support\Assessment\DomainColorPalette;

/**
 * A Pauta de hoje, pronta a ser escrita em CSV ou em Excel — o único sítio que a compõe para exportar.
 *
 * FONTE ÚNICA. `EvaluationSheetCsvExportController` (o CSV e o Excel da Pauta) e o «Pacote de reunião»
 * leem daqui o mesmo documento: a mesma leitura da pauta viva, as mesmas cores de domínio pela mesma
 * costura. O que fica com quem chama é o que não é o documento — quem pode ver a turma, que período se
 * pede, o rasto de auditoria e a resposta.
 */
class LiveEvaluationSheet
{
    public function __construct(protected BuildEvaluationSheet $builder) {}

    /**
     * @return array{document: EvaluationSheetDocument, students: int, domains: int} the document, and the counts the trail records
     */
    public function for(SchoolClass $class, AcademicPeriod $period, SheetMomentKind $moment = SheetMomentKind::Final): array
    {
        $sheet = $this->builder->for($class, $period, ClassificationScope::Period);

        // A MESMA COR QUE O ECRÃ MOSTRA, pela mesma costura — o Excel pinta os
        // domínios com ela, e uma segunda paleta daria um ficheiro que não se
        // parece com a pauta de onde saiu.
        $sheet['domains'] = DomainColorPalette::decorate($sheet['domains']);

        return [
            'document' => EvaluationSheetDocument::fromLiveSheet($class, $period, $sheet, ClassificationScope::Period, $moment),
            'students' => count($sheet['students']),
            'domains' => count($sheet['domains']),
        ];
    }
}
