<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\SchoolClass;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\Export\EvaluationSheetCsvWriter;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use App\Services\Assessment\Export\EvaluationSheetXlsxWriter;
use App\Services\Audit\AuditLog;
use App\Support\Assessment\DomainColorPalette;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A Pauta de Avaliação do período que está a ser visto, em CSV ou em Excel.
 *
 * UM FICHEIRO DE DADOS, NÃO UMA FOTOGRAFIA DO ECRÃ. Esta é a diferença
 * deliberada face à impressão, que vive no próprio ecrã e sai exatamente como
 * o professor a deixou (WYSIWYG): aqui EXPORTA-SE SEMPRE TUDO — todos os
 * domínios, o quantitativo, as apreciações, o global, a autoavaliação quando
 * existe, a proposta e a decisão — independentemente dos toggles «Mostrar:».
 * Esses toggles são apresentação e vivem só no browser; um ficheiro de dados
 * que omitisse colunas em silêncio conforme o que estava no ecrã seria uma
 * armadilha, porque quem o abre uma semana depois não tem como saber o que
 * faltava. O servidor nem sequer conhece os toggles, e é por isso que não os
 * pode obedecer por acidente.
 *
 * O ESTADO DE AGORA, E DITO COMO TAL. O ficheiro do HISTÓRICO é outro
 * controlador (`EvaluationSheetSnapshotExportController`) e lê um payload
 * congelado; este lê a pauta viva. As colunas são as mesmas de propósito — um
 * professor que junte os dois está a comparar dois momentos e colunas
 * desalinhadas tornariam isso adivinhação — mas as duas origens nunca se
 * cruzam.
 *
 * NÃO É A «Preparar exportação para o Inovar» (§6). Aquela produz a grelha da
 * escola a partir de uma pauta guardada, deixa registo no histórico e um
 * ficheiro imutável; esta é uma leitura, aqui e agora, do que está no ecrã.
 * Saídas diferentes, propósitos diferentes.
 *
 * NÃO CRIA SNAPSHOT NEM ENTRADA NO HISTÓRICO (§10): o histórico é para
 * momentos guardados e para as exportações Inovar. Deixa rasto de AUDITORIA,
 * porque uma exportação em massa de classificações é isso mesmo (§22.5) — e
 * o rasto leva contagens e identificadores, nunca nomes de alunos.
 */
class EvaluationSheetCsvExportController extends Controller
{
    public function __construct(
        protected BuildEvaluationSheet $builder,
        protected EvaluationSheetCsvWriter $csv,
        protected EvaluationSheetXlsxWriter $xlsx,
        protected AuditLog $audit,
    ) {}

    public function csv(SchoolClass $class, ?string $period = null): HttpResponse
    {
        $document = $this->documentFor($class, $period, 'csv');

        return response($this->csv->write($document), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->legacyName($document).'.csv"',
        ]);
    }

    public function xlsx(SchoolClass $class, ?string $period = null): HttpResponse
    {
        $document = $this->documentFor($class, $period, 'xlsx');

        return response($this->xlsx->write($document), 200, [
            'Content-Type' => $this->xlsx->contentType(),
            'Content-Disposition' => 'attachment; filename="'.$document->fileStem().'.xlsx"',
        ]);
    }

    protected function documentFor(SchoolClass $class, ?string $period, string $format): EvaluationSheetDocument
    {
        Gate::authorize('view', $class);

        $periods = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')
            ->get();

        // A mesma resolução de período do ecrã (EvaluationSheetController::show):
        // sem período no URL abre-se o primeiro. Um ulid que não seja um período
        // desta turma não é um pedido válido — 404, nunca um ficheiro do
        // período errado com o nome do pedido.
        $selected = $period !== null
            ? $periods->firstWhere('ulid', $period)
            : $periods->first();

        abort_if($selected === null, 404);

        $sheet = $this->builder->for($class, $selected, ClassificationScope::Period);

        // A MESMA COR QUE O ECRÃ MOSTRA, pela mesma costura — o Excel pinta os
        // domínios com ela, e uma segunda paleta daria um ficheiro que não se
        // parece com a pauta de onde saiu.
        $sheet['domains'] = DomainColorPalette::decorate($sheet['domains']);

        // Auditoria (§22.5): uma exportação em massa de classificações deixa
        // rasto. SEM DADOS PESSOAIS — contagens e identificadores, como o resto
        // do projeto faz; nenhum nome de aluno entra num evento de auditoria.
        $this->audit->record(
            'report.exported',
            $class,
            summary: "Pauta de Avaliação de {$class->label} exportada em ".strtoupper($format).'.',
            properties: [
                'format' => $format,
                'source' => 'evaluation_sheet',
                'academic_period_ulid' => $selected->ulid,
                'scope' => ClassificationScope::Period->value,
                'student_count' => count($sheet['students']),
                'domain_count' => count($sheet['domains']),
            ],
        );

        return EvaluationSheetDocument::fromLiveSheet($class, $selected, $sheet, ClassificationScope::Period);
    }

    /**
     * O nome que o CSV da pauta viva sempre teve.
     *
     * Deliberadamente NÃO o `fileStem()` do documento: já há ficheiros
     * descarregados com este nome, e mudá-lo agora não melhorava nada que
     * compensasse partir o hábito de quem os arruma. O Excel, que é novo, usa
     * o nome novo.
     */
    protected function legacyName(EvaluationSheetDocument $document): string
    {
        return 'pauta-avaliacao_'
            .str($document->classLabel)->slug()
            .'_'.str($document->periodLabel)->slug();
    }
}
