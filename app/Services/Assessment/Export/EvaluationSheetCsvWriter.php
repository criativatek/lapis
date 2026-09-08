<?php

namespace App\Services\Assessment\Export;

use RuntimeException;

/**
 * A pauta em CSV — a de hoje e a que ficou guardada, pelo mesmo escritor.
 *
 * UM FICHEIRO DE DADOS, NÃO UMA FOTOGRAFIA DO ECRÃ. Ao contrário da impressão,
 * que sai como o professor deixou o ecrã, aqui SAI SEMPRE TUDO: todos os
 * domínios, o quantitativo, as apreciações, o global, a autoavaliação quando o
 * documento a tem, a proposta e a decisão. Os toggles «Mostrar:» são
 * apresentação, vivem só no browser, e um ficheiro que omitisse colunas em
 * silêncio conforme o que estava no ecrã seria uma armadilha para quem o abre
 * uma semana depois.
 *
 * A PROPOSTA E A DECISÃO TÊM COLUNAS SEPARADAS, e isso não é zelo: num ficheiro
 * não há negrito nem itálico, e a distinção que o ecrã faz pela tipografia tem
 * de ser feita aqui pela estrutura. Uma proposta escrita na coluna da nota
 * seria exatamente o que §3.3 proíbe — o sistema a dar por decidido o que o
 * professor ainda não decidiu.
 *
 * O CABEÇALHO DIZ DE QUE PAUTA SE TRATA. Um CSV descarregado perde o contexto
 * no segundo seguinte: a turma, a disciplina, o momento e a data de referência
 * viajam no ficheiro, além do nome dele.
 */
class EvaluationSheetCsvWriter
{
    /** BOM UTF-8, para o Excel em Windows abrir os acentos em vez de mojibake. */
    public const BOM = "\xEF\xBB\xBF";

    public function write(EvaluationSheetDocument $document): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Não foi possível gerar o ficheiro CSV.');
        }

        foreach ($this->preamble($document) as $line) {
            fputcsv($handle, $line);
        }

        fputcsv($handle, $this->header($document));

        foreach ($document->students as $student) {
            fputcsv($handle, $this->row($document, $student));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return self::BOM.$csv;
    }

    /**
     * Quem é esta pauta, antes da tabela.
     *
     * NADA AQUI É A HORA ATUAL. Duas exportações seguidas da mesma pauta viva
     * têm de produzir os mesmos bytes; um carimbo de «gerado em» tornaria
     * qualquer comparação entre dois ficheiros num exercício de ignorar a
     * primeira linha. A data que interessa — a do momento — é a do documento.
     *
     * @return list<list<string>>
     */
    protected function preamble(EvaluationSheetDocument $document): array
    {
        $lines = [
            ['Pauta de Avaliação', $document->momentLabel],
            ['Turma', $document->classLabel],
            ['Disciplina', $document->subject],
            ['Ano letivo', $document->academicYear],
            [$document->periodKindLabel, $document->periodLabel],
            ['Âmbito', $document->scopeLabel],
        ];

        if ($document->effectiveAt !== null) {
            $lines[] = ['Data de referência', $document->effectiveAt];
        }

        if ($document->isHistorical) {
            $lines[] = ['Pauta guardada em', (string) $document->keptAt];

            if ($document->authorName !== null) {
                $lines[] = ['Guardada por', $document->authorName];
            }

            $lines[] = ['Origem dos valores', 'Momento guardado — não reflete alterações posteriores.'];
        }

        $lines[] = [];

        return $lines;
    }

    /**
     * @return list<string>
     */
    protected function header(EvaluationSheetDocument $document): array
    {
        $withSelfAssessment = $document->hasSelfAssessment();
        $header = ['Nº', 'Aluno'];

        foreach ($document->domains as $domain) {
            $name = (string) $domain['name'];
            $header[] = "{$name} — Percentagem";
            // TRÊS COLUNAS, TRÊS AFIRMAÇÕES DIFERENTES. «Apreciação» é o que
            // vale — a decisão do professor quando existe, a proposta quando
            // não —, e as outras duas dizem de onde ela veio. Num ficheiro não
            // há itálico nem negrito, e a distinção que o ecrã faz pela
            // tipografia tem de ser feita aqui pela estrutura (§11).
            $header[] = "{$name} — Apreciação";
            $header[] = "{$name} — Proposta do Lapispro";
            $header[] = "{$name} — Origem da apreciação";

            if ($withSelfAssessment) {
                $header[] = "{$name} — Autoavaliação";
            }
        }

        // O global vem em TRÊS colunas, não nas duas do ecrã, e cada uma com a
        // sua unidade — quem lê «5» num ficheiro não sabe se são 5% ou um 5
        // numa escala de 1 a 5. Exportar mais nunca é o problema; exportar
        // ambíguo é.
        //
        // «Global — Percentagem» é a MESMA leitura que a coluna «Quant.» do
        // ecrã mostra, e a mesma que cada domínio mostra ao lado dela. Foi
        // assim que a divergência apareceu: durante algum tempo a grelha punha
        // o valor na escala nessa coluna quando ele existia, e um «3.000»
        // aparecia no meio de percentagens. O ficheiro estava certo e o ecrã é
        // que não — hoje as duas leituras são uma só.
        $header[] = 'Global — Percentagem';
        $header[] = 'Global — Valor na escala';
        $header[] = 'Global — Nível';

        if ($withSelfAssessment) {
            $header[] = 'Autoavaliação global';
        }

        // DUAS COLUNAS, DUAS COISAS. O que o Lapispro propôs, e o que o
        // professor decidiu. «Origem do nível» diz por palavras qual das duas
        // está preenchida, porque um ficheiro lido meses depois não tem o ecrã
        // ao lado para explicar a diferença.
        $header[] = 'Proposta do Lapispro';
        $header[] = 'Nível atribuído';
        $header[] = 'Origem do nível';

        // O que o toggle «Indicadores de cobertura» assinala no ecrã, dito em
        // texto: onde é que o valor assenta em elementos parciais ou ausentes.
        $header[] = 'Avisos de cobertura';

        return $header;
    }

    /**
     * @param  array<string, mixed>  $student
     * @return list<string>
     */
    protected function row(EvaluationSheetDocument $document, array $student): array
    {
        $withSelfAssessment = $document->hasSelfAssessment();

        /** @var array<int, array<string, mixed>> $studentDomains */
        $studentDomains = $student['domains'] ?? [];
        $byDomainId = [];

        foreach ($studentDomains as $studentDomain) {
            $byDomainId[(int) $studentDomain['domain_id']] = $studentDomain;
        }

        $row = [
            ($student['class_number'] ?? null) === null ? '' : (string) $student['class_number'],
            (string) $student['name'],
        ];

        $warnings = [];

        foreach ($document->domains as $domain) {
            $domainId = (int) $domain['domain_id'];
            $studentDomain = $byDomainId[$domainId] ?? null;

            $row[] = $this->percentage($studentDomain === null ? null : $studentDomain['normalized_value']);

            $proposed = $studentDomain === null
                ? ''
                : (string) ($studentDomain['scale_level_code'] ?? $studentDomain['scale_level_label'] ?? '');
            $decided = $studentDomain === null
                ? ''
                : (string) ($studentDomain['decided_scale_level_code'] ?? $studentDomain['decided_scale_level_label'] ?? '');

            // A apreciação que vale, a proposta sempre preservada ao lado, e a
            // origem dita por palavras — porque um ficheiro lido meses depois
            // não tem o ecrã ao lado para explicar a diferença.
            $row[] = $decided !== '' ? $decided : $proposed;
            $row[] = $proposed;
            $row[] = $decided !== ''
                ? 'Decisão do professor'
                : ($proposed !== '' ? 'Proposta do Lapispro' : '');

            if ($withSelfAssessment) {
                $row[] = (string) ($studentDomain['self_assessment']['code'] ?? '');
            }

            if ($studentDomain !== null && ($studentDomain['has_coverage_warning'] ?? false) === true) {
                $warnings[] = (string) $domain['name'];
            }
        }

        /** @var array<string, mixed> $overall */
        $overall = $student['overall'];

        $row[] = $this->percentage($overall['normalized_value']);
        $row[] = (string) ($overall['scale_value'] ?? '');
        $row[] = (string) ($overall['scale_level_code'] ?? $overall['scale_level_label'] ?? '');

        if ($withSelfAssessment) {
            $row[] = (string) ($student['self_assessment']['code'] ?? '');
        }

        /** @var array<string, mixed>|null $classification */
        $classification = $student['classification'] ?? null;

        $row[] = $this->proposed($classification);
        [$decided, $origin] = $this->decided($classification);
        $row[] = $decided;
        $row[] = $origin;

        if (($overall['has_coverage_warning'] ?? false) === true) {
            array_unshift($warnings, 'Global');
        }

        $row[] = $warnings === [] ? '' : implode('; ', $warnings);

        return $row;
    }

    /**
     * A proposta do Lapispro, na escala — nunca a percentagem normalizada que a
     * produziu, e nunca na coluna da decisão.
     *
     * @param  array<string, mixed>|null  $classification
     */
    protected function proposed(?array $classification): string
    {
        if ($classification === null) {
            return '';
        }

        $proposed = $classification['proposed_scale_level_code']
            ?? $classification['proposed_scale_level_label']
            ?? $classification['proposed_value'];

        return $proposed === null ? '' : (string) $proposed;
    }

    /**
     * A DECISÃO DO PROFESSOR, e só ela.
     *
     * Vazia enquanto ninguém decidiu — e a coluna ao lado diz isso por palavras,
     * em vez de deixar uma proposta a passar por nota (§3.3).
     *
     * @param  array<string, mixed>|null  $classification
     * @return array{string, string}
     */
    protected function decided(?array $classification): array
    {
        if ($classification === null) {
            return ['', 'Sem classificação registada'];
        }

        $final = $classification['final_scale_level_code']
            ?? $classification['final_scale_level_label']
            ?? $classification['final_value'];

        if ($final !== null) {
            return [(string) $final, 'Decisão do professor'];
        }

        $proposed = $classification['proposed_scale_level_code']
            ?? $classification['proposed_scale_level_label']
            ?? $classification['proposed_value'];

        return ['', $proposed === null
            ? 'Sem classificação registada'
            : 'Proposta do Lapispro (não decidida)'];
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
