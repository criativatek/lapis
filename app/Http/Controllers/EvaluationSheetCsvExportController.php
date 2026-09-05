<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\SchoolClass;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Audit\AuditLog;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A Pauta de Avaliação do período que está a ser visto, em CSV.
 *
 * UM FICHEIRO DE DADOS, NÃO UMA FOTOGRAFIA DO ECRÃ. Esta é a diferença
 * deliberada face à impressão, que vive no próprio ecrã e sai exatamente como
 * o professor a deixou (WYSIWYG): aqui EXPORTA-SE SEMPRE TUDO — todos os
 * domínios, o quantitativo, as apreciações, o global e o nível atribuído —
 * independentemente dos toggles «Mostrar:». Esses toggles são apresentação e
 * vivem só no browser; um ficheiro de dados que omitisse colunas em silêncio
 * conforme o que estava no ecrã seria uma armadilha, porque quem o abre uma
 * semana depois não tem como saber o que faltava. O servidor nem sequer
 * conhece os toggles, e é por isso que não os pode obedecer por acidente.
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
        protected AuditLog $audit,
    ) {}

    public function __invoke(SchoolClass $class, ?string $period = null): HttpResponse
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

        /** @var list<array<string, mixed>> $domains */
        $domains = $sheet['domains'];
        /** @var list<array<string, mixed>> $students */
        $students = $sheet['students'];

        // Auditoria (§22.5): uma exportação em massa de classificações deixa
        // rasto. SEM DADOS PESSOAIS — contagens e identificadores, como o resto
        // do projeto faz; nenhum nome de aluno entra num evento de auditoria.
        $this->audit->record(
            'report.exported',
            $class,
            summary: "Pauta de Avaliação de {$class->label} exportada em CSV.",
            properties: [
                'format' => 'csv',
                'source' => 'evaluation_sheet',
                'academic_period_ulid' => $selected->ulid,
                'scope' => ClassificationScope::Period->value,
                'student_count' => count($students),
                'domain_count' => count($domains),
            ],
        );

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Não foi possível gerar o ficheiro CSV.');
        }

        fputcsv($handle, $this->header($domains));

        foreach ($students as $student) {
            fputcsv($handle, $this->row($student, $domains));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $filename = 'pauta-avaliacao_'
            .str($class->label)->slug()
            .'_'.str((string) $selected->label)->slug()
            .'.csv';

        // BOM UTF-8, como o CSV da pauta antiga já fazia, para o Excel abrir os
        // acentos portugueses bem em vez de os partir em mojibake.
        return response("\xEF\xBB\xBF".$csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $domains
     * @return list<string>
     */
    protected function header(array $domains): array
    {
        $header = ['Nº', 'Aluno'];

        foreach ($domains as $domain) {
            $name = (string) $domain['name'];
            $header[] = "{$name} — Percentagem";
            $header[] = "{$name} — Apreciação";
        }

        // O global vem em TRÊS colunas, não nas duas do ecrã. Na grelha a
        // coluna «Quant.» mostra o valor na escala quando existe e a
        // percentagem quando não existe — uma coluna, duas unidades. Num
        // ficheiro isso é ambíguo: quem lê «5» não sabe se são 5% ou um 5 numa
        // escala de 1 a 5. Aqui cada unidade tem a sua coluna, e uma delas
        // fica vazia. Exportar mais nunca é o problema; exportar ambíguo é.
        $header[] = 'Global — Percentagem';
        $header[] = 'Global — Valor na escala';
        $header[] = 'Global — Apreciação';

        // O ecrã distingue a decisão da proposta pela tipografia (negrito vs.
        // itálico). Num CSV não há tipografia, e uma proposta que se lesse como
        // uma nota seria exatamente o erro que §3.3 proíbe — por isso a origem
        // viaja numa coluna própria, escrita por extenso.
        $header[] = 'Nível atribuído';
        $header[] = 'Origem do nível';

        // O que o toggle «Indicadores de cobertura» assinala no ecrã, dito em
        // texto: onde é que o valor assenta em elementos parciais ou ausentes.
        $header[] = 'Avisos de cobertura';

        return $header;
    }

    /**
     * @param  array<string, mixed>  $student
     * @param  list<array<string, mixed>>  $domains
     * @return list<string>
     */
    protected function row(array $student, array $domains): array
    {
        /** @var array<int, array<string, mixed>> $studentDomains */
        $studentDomains = $student['domains'];
        $byDomainId = [];

        foreach ($studentDomains as $studentDomain) {
            $byDomainId[(int) $studentDomain['domain_id']] = $studentDomain;
        }

        $row = [
            $student['class_number'] === null ? '' : (string) $student['class_number'],
            (string) $student['name'],
        ];

        $warnings = [];

        foreach ($domains as $domain) {
            $domainId = (int) $domain['domain_id'];
            $studentDomain = $byDomainId[$domainId] ?? null;

            $row[] = $this->percentage($studentDomain === null ? null : $studentDomain['normalized_value']);
            $row[] = $studentDomain === null ? '' : (string) ($studentDomain['scale_level_label'] ?? '');

            if ($studentDomain !== null && $studentDomain['has_coverage_warning'] === true) {
                $warnings[] = (string) $domain['name'];
            }
        }

        /** @var array<string, mixed> $overall */
        $overall = $student['overall'];

        $row[] = $this->percentage($overall['normalized_value']);
        $row[] = (string) ($overall['scale_value'] ?? '');
        $row[] = (string) ($overall['scale_level_label'] ?? '');

        if ($overall['has_coverage_warning'] === true) {
            array_unshift($warnings, 'Global');
        }

        /** @var array<string, mixed>|null $classification */
        $classification = $student['classification'];
        [$level, $origin] = $this->assignedLevel($classification);

        $row[] = $level;
        $row[] = $origin;
        $row[] = $warnings === [] ? '' : implode('; ', $warnings);

        return $row;
    }

    /**
     * O nível que o ecrã mostra na coluna fixa da direita, e DE ONDE VEM.
     *
     * A decisão do professor quando existe; a proposta do Lapispro quando ainda
     * não foi decidida — nunca as duas confundidas, e nunca uma proposta
     * apresentada como se fosse uma nota (§3.3).
     *
     * @param  array<string, mixed>|null  $classification
     * @return array{string, string}
     */
    protected function assignedLevel(?array $classification): array
    {
        if ($classification === null) {
            return ['', ''];
        }

        $final = $classification['final_scale_level_label'] ?? $classification['final_value'];

        if ($final !== null) {
            return [(string) $final, 'Decisão do professor'];
        }

        $proposed = $classification['proposed_scale_level_label'] ?? $classification['proposed_value'];

        if ($proposed !== null) {
            return [(string) $proposed, 'Proposta do Lapispro (não decidida)'];
        }

        return ['', ''];
    }

    /**
     * A percentagem do motor com uma casa decimal, VAZIA quando não há valor.
     *
     * Nunca «0»: sem elementos não é zero (§13.3), e uma célula vazia é a única
     * escrita que não convida uma folha de cálculo a somar a ausência.
     *
     * Ponto decimal, não vírgula, e o mesmo delimitador do CSV antigo: o
     * ficheiro continua a abrir da mesma maneira que a pauta que substitui.
     */
    protected function percentage(mixed $value): string
    {
        if ($value === null || ! is_numeric($value)) {
            return '';
        }

        return number_format((float) $value, 1, '.', '');
    }
}
