<?php

namespace App\Http\Controllers;

use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Services\Assessment\Export\EvaluationSheetCsvWriter;
use App\Services\Assessment\Export\EvaluationSheetDocument;
use App\Services\Assessment\Export\EvaluationSheetXlsxWriter;
use App\Services\Audit\AuditLog;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Uma pauta GUARDADA, em CSV ou em Excel.
 *
 * ESTE CONTROLADOR NÃO SABE CALCULAR NADA, e isso é estrutural e não um hábito.
 * Não recebe `BuildEvaluationSheet`, não recebe o calculador, não recebe a
 * escala. Recebe um escritor de ficheiros e um payload congelado. Uma edição
 * futura que quisesse «atualizar os valores» não teria por onde: o presente não
 * está nesta sala.
 *
 * A regra que isto protege é simples de dizer e fácil de partir por distração:
 * o ficheiro de um momento representa esse momento. Se o professor mudou de 3
 * para 4 a 12 de novembro, o ficheiro do momento de 10 continua a dizer 3, e
 * quem quiser o 4 exporta o momento novo (§9, §16).
 *
 * NÃO É A EXPORTAÇÃO PARA O INOVAR. Aquela preenche a grelha da escola e deixa
 * um registo no histórico; esta lê um registo que já existe e entrega-o numa
 * forma que se abre no Excel. Nenhuma das duas cria seja o que for.
 */
class EvaluationSheetSnapshotExportController extends Controller
{
    public function __construct(
        protected EvaluationSheetCsvWriter $csv,
        protected EvaluationSheetXlsxWriter $xlsx,
        protected AuditLog $audit,
    ) {}

    public function csv(SchoolClass $class, EvaluationSheetExport $export): HttpResponse
    {
        $document = $this->documentFor($class, $export, 'csv');

        return response($this->csv->write($document), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$document->fileStem().'.csv"',
        ]);
    }

    public function xlsx(SchoolClass $class, EvaluationSheetExport $export): HttpResponse
    {
        $document = $this->documentFor($class, $export, 'xlsx');

        return response($this->xlsx->write($document), 200, [
            'Content-Type' => $this->xlsx->contentType(),
            'Content-Disposition' => 'attachment; filename="'.$document->fileStem().'.xlsx"',
        ]);
    }

    /**
     * O documento congelado, depois de quatro portas.
     *
     * A turma tem de ser uma que este professor possa ver; o registo tem de ser
     * DAQUELA turma (um ulid não é a chave da turma de um colega, mesmo dentro
     * da mesma organização); e o payload tem de continuar a corresponder ao seu
     * próprio selo. Um documento que já não bate certo com o registo não é
     * prova de nada, e entregá-lo em Excel seria dar-lhe a aparência de uma
     * folha oficial (§13).
     */
    protected function documentFor(SchoolClass $class, EvaluationSheetExport $export, string $format): EvaluationSheetDocument
    {
        Gate::authorize('view', $class);

        abort_unless((int) $export->class_id === (int) $class->id, 404);

        if (! $export->isIntact()) {
            Log::warning('Evaluation sheet snapshot failed its integrity check on export.', [
                'evaluation_sheet_export_ulid' => $export->ulid,
                'class_id' => (int) $export->class_id,
                'format' => $format,
            ]);

            abort(409, 'Esta pauta guardada já não corresponde ao registo original e por isso não pode ser exportada.');
        }

        // Auditoria (§22.5): uma exportação em massa de classificações deixa
        // rasto. SEM DADOS PESSOAIS — contagens e identificadores, nunca nomes.
        $this->audit->record(
            'report.exported',
            $class,
            summary: "Pauta guardada de {$class->label} exportada em ".strtoupper($format).'.',
            properties: [
                'format' => $format,
                'source' => 'evaluation_sheet_snapshot',
                'evaluation_sheet_export_ulid' => $export->ulid,
                'scope' => $export->scope->value,
            ],
        );

        return EvaluationSheetDocument::fromSnapshot($export);
    }
}
