<?php

namespace App\Services\Assessment\Export;

use App\Support\Assessment\ReadingVocabulary;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * O QUADRO SÍNTESE EM EXCEL — o ficheiro do Lapispro, não a grelha do Inovar.
 *
 * SEIS FOLHAS, PORQUE SÃO SEIS PERGUNTAS. Uma única folha com o ano inteiro ao
 * nível do elemento teria centenas de colunas e não se leria; separadas, cada
 * uma responde ao que uma pessoa foi lá procurar:
 *
 *   Quadro Síntese              o ano de cada aluno, momento a momento, e a
 *                               avaliação contínua que o fecha
 *   Domínios                    o mesmo, aberto por domínio
 *   Contínua Final por Domínio  em que é que o ano deu em CADA domínio: o
 *                               resultado formal de cada unidade, a média que
 *                               eles formam e a apreciação que vale
 *   Desempenho acumulado        de onde vem aquele número — os pontos de cada
 *                               unidade, o peso efetivo que eles criam, e a
 *                               fração única que formam
 *   Elementos de Avaliação      o que cada aluno fez, elemento a elemento
 *   Configuração                a escala, os pesos, a legenda — o que faz o
 *                               resto continuar compreensível daqui a três anos
 *
 * AS DUAS FOLHAS DO MEIO NÃO SÃO A MESMA COISA DUAS VEZES, e a ordem diz qual é
 * qual: «Contínua Final por Domínio» é a conclusão FORMAL — a média dos
 * resultados formais das unidades — e «Desempenho acumulado» é a leitura
 * ANALÍTICA, que reprocessa os elementos brutos do ano. Dão números diferentes
 * de propósito.
 *
 * A FOLHA DO ACUMULADO EXISTE PORQUE O NÚMERO NÃO SE EXPLICA SOZINHO. Quem veja
 * 68 % num semestre, 25 % no outro e 60 % de desempenho acumulado tem à frente
 * um número que não é a média de dois; a folha mostra porquê — 85 em 125 contra
 * 7,33 em 29, e 92,33 em 154 ao todo. No ecrã essa conta abre-se ao clicar; num
 * ficheiro não há onde clicar, e por isso vem escrita.
 *
 * A ÚLTIMA FOLHA É A QUE JUSTIFICA AS OUTRAS. Um ficheiro aberto em 2029 tem de
 * poder ser lido sem a aplicação ao lado: sem saber que escala era, que pesos
 * tinham os domínios e o que era um momento intercalar, as colunas anteriores
 * são números sem unidade.
 *
 * AS INTERCALARES ESTÃO LÁ E NÃO ENTRAM NA MÉDIA (§56). Aparecem na cronologia
 * porque fazem parte do trajeto, e a coluna «Entra na avaliação contínua» diz,
 * em cada momento e por escrito, se conta — para que a folha não dependa de
 * quem a lê saber a regra de cor.
 *
 * NÚMEROS COMO NÚMEROS, NÍVEIS COMO TEXTO, exatamente como na pauta: um «4»
 * numa escala de 1 a 5 não é uma quantidade a somar, e uma folha que o
 * convertesse convidaria a fazer médias de níveis, que não é uma operação que
 * exista.
 */
class ClassSynopsisXlsxWriter
{
    private const HEADER_FILL = 'E5E7EB';

    private const BORDER = 'D1D5DB';

    private const STRIPE = 'F7F8FA';

    /** As fotografias distinguem-se dos momentos formais por fundo E por palavras. */
    private const INTERIM_FILL = 'F1F5F9';

    /**
     * A COR DE UMA APRECIAÇÃO, PELA POSIÇÃO DO NÍVEL NA ESCALA (§24, §57).
     *
     * As mesmas quatro tintas que o ecrã usa, e pela mesma regra: um nível
     * negativo é vermelho, e os positivos repartem-se por âmbar, azul e verde
     * conforme a sua posição relativa entre os positivos DA SUA PRÓPRIA ESCALA.
     * O «5» é verde por ser o mais alto, não por ser cinco — uma escala
     * «NS/S/SB» pinta-se exatamente igual.
     *
     * E NUNCA É A ÚNICA INFORMAÇÃO: a célula continua a escrever o código ou a
     * menção, e a folha «Configuração» diz o que a cor significa.
     *
     * @var array<string, string>
     */
    private const TONES = [
        'green' => 'D1FAE5',
        'blue' => 'DBEAFE',
        'amber' => 'FEF3C7',
        'red' => 'FEE2E2',
    ];

    /**
     * @param  array<string, mixed>  $synopsis  o que `BuildClassSynopsis::for()` devolveu
     * @param  array<string, mixed>  $context  turma, disciplina, ano letivo, escala
     * @param  array<int, array<int, array<string, mixed>>>  $accumulated  o que `AccumulatedBreakdown::forClassByDomain()` devolveu, por matrícula e domínio
     */
    public function write(array $synopsis, array $context, array $accumulated = []): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Quadro Síntese — '.(string) $context['class_label'])
            ->setSubject('Quadro Síntese')
            ->setDescription(sprintf(
                '%s · %s · %s',
                (string) $context['class_label'],
                (string) $context['subject'],
                (string) $context['academic_year'],
            ))
            ->setCreator('Lapispro');

        // A ESCALA, POR ORDEM — é dela que sai a tinta de cada apreciação, pela
        // POSIÇÃO do nível e nunca pelo número que ele calha ter (§24, §57).
        // `array_values` porque o resto do escritor precisa de uma lista, e uma
        // turma sem perfil chega aqui sem escala nenhuma.
        $bands = array_values(is_array($context['scale_levels'] ?? null) ? $context['scale_levels'] : []);

        $this->writeSynopsisSheet($spreadsheet->getActiveSheet(), $synopsis, $context, $bands);
        $this->writeDomainsSheet($spreadsheet->createSheet(), $synopsis, $bands);
        // A ORDEM DAS FOLHAS É A HIERARQUIA DAS LEITURAS (§14): os resultados
        // formais de cada momento, depois a conclusão FORMAL do ano por domínio,
        // e só então a leitura ANALÍTICA.
        $this->writeContinuousByDomainSheet($spreadsheet->createSheet(), $synopsis, $bands);
        $this->writeAccumulatedSheet($spreadsheet->createSheet(), $synopsis, $context, $accumulated);
        $this->writeElementsSheet($spreadsheet->createSheet(), $synopsis);
        $this->writeConfigurationSheet($spreadsheet->createSheet(), $synopsis, $context);

        $spreadsheet->setActiveSheetIndex(0);

        return $this->render($spreadsheet);
    }

    // ------------------------------------------------------- folha 1: síntese

    /**
     * @param  array<string, mixed>  $synopsis
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $bands
     */
    protected function writeSynopsisSheet(Worksheet $sheet, array $synopsis, array $context, array $bands = []): void
    {
        $sheet->setTitle('Quadro Síntese');

        $headerRow = $this->writeTitleBlock($sheet, $context, 'Quadro Síntese');
        $secondRow = $headerRow + 1;

        /** @var list<array<string, mixed>> $moments */
        $moments = $synopsis['moments'];

        $sheet->setCellValue([1, $headerRow], 'Nº');
        $sheet->setCellValue([2, $headerRow], 'Aluno');
        $sheet->mergeCells([1, $headerRow, 1, $secondRow]);
        $sheet->mergeCells([2, $headerRow, 2, $secondRow]);

        $column = 3;
        $momentColumns = [];

        foreach ($moments as $moment) {
            $momentColumns[(string) $moment['key']] = $column;

            $sheet->setCellValue([$column, $headerRow], (string) $moment['label']);
            $sheet->mergeCells([$column, $headerRow, $column + 3, $headerRow]);

            $sheet->setCellValue([$column, $secondRow], 'Quant.');
            $sheet->setCellValue([$column + 1, $secondRow], 'Apreciação vigente');
            $sheet->setCellValue([$column + 2, $secondRow], 'Origem');
            $sheet->setCellValue([$column + 3, $secondRow], 'Tendência');

            if (! $moment['is_formal']) {
                $this->fill($sheet, $column, $headerRow, $column + 3, $secondRow, self::INTERIM_FILL);
            }

            $column += 4;
        }

        $continuousColumn = $column;
        $sheet->setCellValue([$column, $headerRow], ReadingVocabulary::CONTINUOUS);
        $sheet->mergeCells([$column, $headerRow, $column + 3, $headerRow]);
        $sheet->setCellValue([$column, $secondRow], 'Média (%)');
        $sheet->setCellValue([$column + 1, $secondRow], 'Proposta');
        $sheet->setCellValue([$column + 2, $secondRow], 'Decisão do professor');
        $sheet->setCellValue([$column + 3, $secondRow], 'Unidades contadas');
        $column += 4;

        $columnCount = $column - 1;
        $row = $secondRow + 1;

        foreach ($synopsis['students'] as $index => $student) {
            if (($student['class_number'] ?? null) !== null) {
                $sheet->setCellValue([1, $row], (int) $student['class_number']);
            }

            $this->text($sheet, 2, $row, (string) $student['name']);

            foreach ($student['moments'] as $reading) {
                $at = $momentColumns[(string) $reading['moment_key']] ?? null;

                if ($at === null) {
                    continue;
                }

                if ($reading['available'] !== true) {
                    // Um momento intercalar que ninguém guardou não tem números
                    // e não os inventa: quatro células vazias e uma palavra que
                    // diz porquê, para não se ler como um resultado nulo (§29).
                    $this->text($sheet, $at + 1, $row, '—');
                    $this->text($sheet, $at + 2, $row, 'Momento não guardado');

                    continue;
                }

                $this->percentage($sheet, $at, $row, $reading['overall']['normalized_value'] ?? null);
                $current = $reading['overall']['current'] ?? null;
                $this->text($sheet, $at + 1, $row, (string) ($current['text'] ?? ''));
                $this->paintLevel($sheet, $at + 1, $row, $current['code'] ?? null, $current['label'] ?? null, $bands);
                $this->text($sheet, $at + 2, $row, $this->originLabel($current['origin'] ?? null));
                $this->text($sheet, $at + 3, $row, $this->trendLabel($reading['trend'] ?? null));
            }

            $continuous = $student['continuous'] ?? null;

            if (is_array($continuous)) {
                $this->percentage($sheet, $continuousColumn, $row, $continuous['normalized_value'] ?? null);
                $this->text($sheet, $continuousColumn + 1, $row, (string) ($continuous['level']['code'] ?? $continuous['proposal']['value'] ?? ''));
                $this->paintLevel(
                    $sheet,
                    $continuousColumn + 1,
                    $row,
                    $continuous['level']['code'] ?? null,
                    $continuous['level']['label'] ?? null,
                    $bands,
                );
                $decision = $continuous['decision'] ?? null;
                $this->text(
                    $sheet,
                    $continuousColumn + 2,
                    $row,
                    (string) ($decision['final']['code'] ?? $decision['final']['label'] ?? $decision['final_value'] ?? ''),
                );
                $sheet->setCellValue([$continuousColumn + 3, $row], (int) ($continuous['counted_units'] ?? 0));
                $sheet->getStyle([$continuousColumn + 3, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }

            if ($index % 2 === 1) {
                $this->fill($sheet, 1, $row, 2, $row, self::STRIPE);
            }

            $row++;
        }

        $this->finish($sheet, $headerRow, $columnCount, $row - 1, freezeAt: 'C', nameWidth: 30);
    }

    // ------------------------------------------------------ folha 2: domínios

    /**
     * @param  array<string, mixed>  $synopsis
     * @param  list<array<string, mixed>>  $bands
     */
    protected function writeDomainsSheet(Worksheet $sheet, array $synopsis, array $bands = []): void
    {
        $sheet->setTitle('Domínios');

        $headers = [
            'Nº', 'Aluno', 'Momento', 'Tipo de momento', 'Entra na avaliação contínua',
            'Domínio', 'Quant. (%)', 'Proposta do Lapispro', 'Decisão do professor',
            'Apreciação vigente', 'Origem', 'Tendência', 'Cobertura',
        ];

        $this->headerRow($sheet, $headers, 1);
        $row = 2;

        /** @var array<string, array<string, mixed>> $momentsByKey */
        $momentsByKey = [];
        foreach ($synopsis['moments'] as $moment) {
            $momentsByKey[(string) $moment['key']] = $moment;
        }

        /** @var array<int, array<string, mixed>> $domainsById */
        $domainsById = [];
        foreach ($synopsis['domains'] as $domain) {
            $domainsById[(int) $domain['domain_id']] = $domain;
        }

        foreach ($synopsis['students'] as $student) {
            foreach ($student['moments'] as $reading) {
                if ($reading['available'] !== true) {
                    continue;
                }

                $moment = $momentsByKey[(string) $reading['moment_key']] ?? null;

                foreach ($reading['domains'] as $cell) {
                    if ($cell['available'] !== true) {
                        continue;
                    }

                    $domain = $domainsById[(int) $cell['domain_id']] ?? null;
                    $current = $cell['current'] ?? null;

                    if (($student['class_number'] ?? null) !== null) {
                        $sheet->setCellValue([1, $row], (int) $student['class_number']);
                    }

                    $this->text($sheet, 2, $row, (string) $student['name']);
                    $this->text($sheet, 3, $row, (string) ($moment['label'] ?? ''));
                    $this->text($sheet, 4, $row, ($moment['is_formal'] ?? false) ? 'Formal' : 'Fotografia (intercalar)');
                    $this->text($sheet, 5, $row, ($moment['is_formal'] ?? false) ? 'Sim' : 'Não');
                    $this->text($sheet, 6, $row, (string) ($domain['name'] ?? ''));
                    $this->percentage($sheet, 7, $row, $cell['normalized_value'] ?? null);
                    $this->text($sheet, 8, $row, (string) ($cell['proposed']['code'] ?? $cell['proposed']['label'] ?? ''));
                    $this->text($sheet, 9, $row, (string) ($cell['decided']['code'] ?? $cell['decided']['label'] ?? ''));
                    $this->text($sheet, 10, $row, (string) ($current['text'] ?? ''));
                    $this->paintLevel($sheet, 10, $row, $current['code'] ?? null, $current['label'] ?? null, $bands);
                    $this->text($sheet, 11, $row, $this->originLabel($current['origin'] ?? null));
                    $this->text($sheet, 12, $row, $this->trendLabel($cell['trend'] ?? null));
                    $this->text($sheet, 13, $row, ($cell['has_coverage_warning'] ?? false) ? 'Parcial ou em falta' : 'Completa');

                    if ($domain !== null) {
                        $this->fill($sheet, 6, $row, 6, $row, $this->rgb($domain), soft: true);
                    }

                    $row++;
                }
            }
        }

        $this->finish($sheet, 1, count($headers), $row - 1, freezeAt: 'C', nameWidth: 28, singleHeaderRow: true);
    }

    // --------------------------------- folha 3: avaliação contínua final

    /**
     * A CONCLUSÃO FORMAL DO ANO, domínio a domínio.
     *
     * UMA LINHA POR (ALUNO, DOMÍNIO), com o resultado formal de cada unidade
     * temporal em coluna própria e a média que eles formam. Quem some as
     * colunas das unidades — pelos pesos que a folha «Configuração» declara —
     * chega à média: é isso que torna o número verificável fora da aplicação.
     *
     * O QUE ENTRA E O QUE NÃO ENTRA, dito também por escrito na «Configuração»:
     * entram os resultados formais das unidades; não entram fotografias
     * intercalares nem o desempenho acumulado, que vive na folha ao lado e
     * responde a outra pergunta.
     *
     * A APRECIAÇÃO VIGENTE é a decisão do professor quando existe e a proposta
     * quando não — e uma proposta que ninguém alterou não é uma pendência: é o
     * que vale. A coluna «Origem» diz qual das duas está a valer, para que a
     * folha não dependa de quem a lê saber a regra.
     *
     * @param  array<string, mixed>  $synopsis
     * @param  list<array<string, mixed>>  $bands
     */
    protected function writeContinuousByDomainSheet(Worksheet $sheet, array $synopsis, array $bands = []): void
    {
        $sheet->setTitle('Contínua Final por Domínio');

        /** @var list<array<string, mixed>> $units */
        $units = $synopsis['continuous']['units'];

        $headers = ['Nº', 'Aluno', 'Domínio'];
        foreach ($units as $unit) {
            $headers[] = (string) $unit['label'].' (%)';
        }
        // OS MESMOS NOMES QUE O ECRÃ, e pela mesma razão: quem exporta o Quadro
        // Síntese está a levar consigo o que acabou de ler, e dois vocabulários
        // para as mesmas seis colunas obrigá-lo-iam a traduzir. «Proposta do
        // Lapispro» e «Decisão do professor» continuam a ser as palavras da
        // ORIGEM — e é na coluna «Origem» que elas vivem; os títulos dizem de
        // que fase do ano se trata, que é a final (§29).
        $headers[] = ReadingVocabulary::FINAL_AVERAGE.' (%)';
        $headers[] = 'Unidades contadas';
        $headers[] = 'Proposta final';
        $headers[] = 'Decisão final';
        $headers[] = 'Menção vigente';
        $headers[] = 'Origem';

        $this->headerRow($sheet, $headers, 1);
        $row = 2;

        /** @var array<int, array<string, mixed>> $domainsById */
        $domainsById = [];
        foreach ($synopsis['domains'] as $domain) {
            $domainsById[(int) $domain['domain_id']] = $domain;
        }

        foreach ($synopsis['students'] as $student) {
            foreach ($student['continuous_domains'] ?? [] as $domainId => $reading) {
                $domain = $domainsById[(int) $domainId] ?? null;

                if (($student['class_number'] ?? null) !== null) {
                    $sheet->setCellValue([1, $row], (int) $student['class_number']);
                }

                $this->text($sheet, 2, $row, (string) $student['name']);
                $this->text($sheet, 3, $row, (string) ($domain['name'] ?? '(domínio removido)'));

                if ($domain !== null) {
                    $this->fill($sheet, 3, $row, 3, $row, $this->rgb($domain), soft: true);
                }

                $column = 4;
                foreach ($reading['units'] as $unit) {
                    $this->percentage($sheet, $column++, $row, $unit['normalized_value'] ?? null);
                }

                $this->percentage($sheet, $column, $row, $reading['normalized_value'] ?? null);
                $sheet->setCellValue([$column + 1, $row], (int) ($reading['counted_units'] ?? 0));
                $sheet->getStyle([$column + 1, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $proposed = $reading['level'] ?? null;
                $decided = $reading['decision']['final'] ?? null;

                $this->text($sheet, $column + 2, $row, (string) ($proposed['code'] ?? $proposed['label'] ?? ''));
                $this->text($sheet, $column + 3, $row, (string) ($decided['code'] ?? $decided['label'] ?? ''));

                $current = $decided ?? $proposed;
                $this->text($sheet, $column + 4, $row, (string) ($current['code'] ?? $current['label'] ?? ''));
                $this->paintLevel($sheet, $column + 4, $row, $current['code'] ?? null, $current['label'] ?? null, $bands);
                $this->text(
                    $sheet,
                    $column + 5,
                    $row,
                    $current === null ? '' : $this->originLabel($decided === null ? 'proposed' : 'decided'),
                );

                $row++;
            }
        }

        $this->finish($sheet, 1, count($headers), $row - 1, freezeAt: 'D', nameWidth: 28, singleHeaderRow: true);
    }

    // ------------------------------------------ folha 4: desempenho acumulado

    /**
     * DE ONDE VEM AQUELE NÚMERO, aluno a aluno e domínio a domínio.
     *
     * UMA LINHA POR UNIDADE, E UMA LINHA DE TOTAL. As linhas de unidade trazem
     * os pontos que ela contribuiu e a fatia do denominador que eles ocupam; a
     * linha de total traz a fração inteira e o resultado. Quem some as unidades
     * chega ao total — é essa soma que torna o número reconstruível fora da
     * aplicação, que é a razão de esta folha existir.
     *
     * O PESO EFETIVO NÃO É UM PESO CONFIGURADO, e a folha «Configuração»
     * diz isso por escrito: é a fatia que as cotações de uma unidade ocupam no
     * total, e muda sozinha à medida que o ano avança.
     *
     * @param  array<string, mixed>  $synopsis
     * @param  array<string, mixed>  $context
     * @param  array<int, array<int, array<string, mixed>>>  $accumulated
     */
    protected function writeAccumulatedSheet(Worksheet $sheet, array $synopsis, array $context, array $accumulated): void
    {
        $sheet->setTitle('Desempenho acumulado');

        $headers = [
            'Nº', 'Aluno', 'Domínio', 'Unidade temporal',
            'Pontos obtidos', 'Cotação', 'Resultado (%)', 'Peso efetivo (%)',
        ];

        $this->headerRow($sheet, $headers, 1);
        $row = 2;

        /** @var array<int, string> $domainNames */
        $domainNames = [];
        foreach ($synopsis['domains'] as $domain) {
            $domainNames[(int) $domain['domain_id']] = (string) $domain['name'];
        }

        foreach ($synopsis['students'] as $student) {
            $byDomain = $accumulated[(int) $student['enrollment_id']] ?? [];

            foreach ($byDomain as $domainId => $reading) {
                foreach ($reading['units'] as $unit) {
                    $this->accumulatedRow(
                        $sheet,
                        $row++,
                        $student,
                        $domainNames[(int) $domainId] ?? '(domínio removido)',
                        (string) $unit['label'],
                        $unit['points_earned'],
                        $unit['points_possible'],
                        $unit['normalized_value'],
                        $unit['effective_weight_percent'],
                    );
                }

                // O TOTAL, na mesma grelha das parcelas para que a soma se veja
                // com os olhos. A negrito porque é a linha que responde.
                $this->accumulatedRow(
                    $sheet,
                    $row,
                    $student,
                    $domainNames[(int) $domainId] ?? '(domínio removido)',
                    'TOTAL — '.(string) ($context['accumulated_period_label'] ?? 'ano'),
                    $reading['points_earned'],
                    $reading['points_possible'],
                    $reading['normalized_value'],
                    '100',
                );

                $sheet->getStyle([1, $row, count($headers), $row])->getFont()->setBold(true);
                $row++;
            }
        }

        $this->finish($sheet, 1, count($headers), $row - 1, freezeAt: 'C', nameWidth: 28, singleHeaderRow: true);
    }

    /**
     * @param  array<string, mixed>  $student
     */
    protected function accumulatedRow(
        Worksheet $sheet,
        int $row,
        array $student,
        string $domainName,
        string $unitLabel,
        ?string $earned,
        ?string $possible,
        ?string $normalized,
        ?string $effectiveWeight,
    ): void {
        if (($student['class_number'] ?? null) !== null) {
            $sheet->setCellValue([1, $row], (int) $student['class_number']);
        }

        $this->text($sheet, 2, $row, (string) $student['name']);
        $this->text($sheet, 3, $row, $domainName);
        $this->text($sheet, 4, $row, $unitLabel);

        // PONTOS COMO NÚMEROS. Uma cotação soma-se e compara-se — ao contrário
        // de um nível, que é uma menção e não uma quantidade.
        foreach ([[5, $earned], [6, $possible]] as [$column, $value]) {
            if ($value === null) {
                $this->text($sheet, $column, $row, '');

                continue;
            }

            $sheet->setCellValue([$column, $row], (float) $value);
            $sheet->getStyle([$column, $row])->getNumberFormat()->setFormatCode('0.00');
        }

        $this->percentage($sheet, 7, $row, $normalized);
        $this->percentage($sheet, 8, $row, $effectiveWeight);
    }

    // ----------------------------------------------------- folha 5: elementos

    /** @param  array<string, mixed>  $synopsis */
    protected function writeElementsSheet(Worksheet $sheet, array $synopsis): void
    {
        $sheet->setTitle('Elementos de Avaliação');

        $headers = [
            'Nº', 'Aluno', 'Unidade temporal', 'Data', 'Elemento', 'Tipo', 'Natureza',
            'Domínios', 'Peso declarado', 'Conta para a classificação',
            'Pontos obtidos', 'Cotação', 'Resultado (%)', 'Nível', 'Estado',
        ];

        $this->headerRow($sheet, $headers, 1);
        $row = 2;

        /** @var array<int, array<string, mixed>> $elementsById */
        $elementsById = [];
        foreach ($synopsis['elements'] as $element) {
            $elementsById[(int) $element['instrument_id']] = $element;
        }

        /** @var array<int, string> $periodLabels */
        $periodLabels = [];
        foreach ($synopsis['periods'] as $period) {
            $periodLabels[(int) $period['id']] = (string) $period['label'];
        }

        foreach ($synopsis['students'] as $student) {
            foreach ($student['elements'] as $result) {
                $element = $elementsById[(int) $result['instrument_id']] ?? null;

                if ($element === null) {
                    continue;
                }

                if (($student['class_number'] ?? null) !== null) {
                    $sheet->setCellValue([1, $row], (int) $student['class_number']);
                }

                $domainNames = array_map(
                    fn (array $domain): string => (string) $domain['name'],
                    $element['domains'],
                );

                $this->text($sheet, 2, $row, (string) $student['name']);
                $this->text($sheet, 3, $row, $periodLabels[(int) $element['academic_period_id']] ?? '');
                $this->text($sheet, 4, $row, (string) $element['applied_on']);
                $this->text($sheet, 5, $row, (string) $element['title']);
                $this->text($sheet, 6, $row, (string) ($element['type'] ?? ''));
                $this->text($sheet, 7, $row, (string) $element['purpose_label']);
                $this->text($sheet, 8, $row, implode(', ', $domainNames));
                // O PESO SÓ QUANDO FOI DECLARADO. Uma célula vazia é «a escola
                // não lhe deu peso»; escrever 0 diria que ela lhe deu zero, que
                // é uma afirmação completamente diferente.
                $this->text($sheet, 9, $row, $element['weight'] === null ? '' : (string) $element['weight']);
                $this->text($sheet, 10, $row, $element['counts_toward_classification'] ? 'Sim' : 'Não');
                // OS PONTOS AO LADO DA PERCENTAGEM. É a cotação, e não o
                // resultado, que diz quanto um elemento pesa no acumulado do
                // ano — e é isso que a folha «Desempenho acumulado» soma.
                foreach ([[11, $result['points_earned'] ?? null], [12, $result['points_possible'] ?? null]] as [$column, $value]) {
                    if ($value === null) {
                        continue;
                    }

                    $sheet->setCellValue([$column, $row], (float) $value);
                    $sheet->getStyle([$column, $row])->getNumberFormat()->setFormatCode('0.00');
                }
                $this->percentage($sheet, 13, $row, $result['normalized_value'] ?? null);
                $this->text($sheet, 14, $row, (string) ($result['level']['code'] ?? $result['level']['label'] ?? ''));
                $this->text($sheet, 15, $row, (string) $result['state_label']);

                $row++;
            }
        }

        $this->finish($sheet, 1, count($headers), $row - 1, freezeAt: 'C', nameWidth: 28, singleHeaderRow: true);
    }

    // -------------------------------------------------- folha 6: configuração

    /**
     * @param  array<string, mixed>  $synopsis
     * @param  array<string, mixed>  $context
     */
    protected function writeConfigurationSheet(Worksheet $sheet, array $synopsis, array $context): void
    {
        $sheet->setTitle('Configuração');

        $row = 1;
        $sheet->setCellValue('A1', 'Configuração e legenda');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $row = 3;

        $row = $this->block($sheet, $row, 'Identificação', [
            ['Turma', (string) $context['class_label']],
            ['Disciplina', (string) $context['subject']],
            ['Ano letivo', (string) $context['academic_year']],
            ['Escala', (string) ($context['scale_name'] ?? '—')],
            ['Exportado em', (string) $context['exported_on']],
        ]);

        $units = [];
        foreach ($synopsis['continuous']['units'] as $unit) {
            $units[] = [
                (string) $unit['kind_label'].' — '.(string) $unit['label'],
                'peso '.(string) $unit['weight_percent'],
            ];
        }

        $row = $this->block($sheet, $row, 'Unidades formais que entram na avaliação contínua', $units === []
            ? [['—', 'Nenhuma unidade configurada']]
            : $units);

        $domains = [];
        foreach ($synopsis['domains'] as $domain) {
            $domains[] = [(string) $domain['name'], (string) $domain['weight_percent'].' %'];
        }

        $row = $this->block($sheet, $row, 'Domínios e o seu peso no perfil', $domains === []
            ? [['—', 'Sem domínios definidos']]
            : $domains);

        $levels = [];
        foreach ($context['scale_levels'] ?? [] as $level) {
            $levels[] = [
                (string) $level['code'].' — '.(string) $level['label'],
                $level['is_negative'] ? 'Nível negativo' : 'Nível positivo',
            ];
        }

        $row = $this->block($sheet, $row, 'Níveis da escala, do mais baixo ao mais alto', $levels === []
            ? [['—', 'Escala sem níveis configurados']]
            : $levels);

        // AS DUAS LEITURAS DO ANO, e a hierarquia entre elas, ditas antes de
        // qualquer outra explicação: são o que este ficheiro mais arrisca ser
        // confundido a dizer.
        $readings = [];
        foreach (ReadingVocabulary::legend() as $reading) {
            $readings[] = [$reading['name'], $reading['explanation']];
        }

        // A FRASE QUE DESFAZ A CONFUSÃO, logo a seguir aos dois nomes. É a
        // primeira coisa que alguém pergunta ao ver os dois números lado a lado.
        $readings[] = ['Porque diferem', ReadingVocabulary::ACCUMULATED_NOT_AN_AVERAGE];
        $readings[] = ['Os pesos de cada uma', ReadingVocabulary::ACCUMULATED_VERSUS_CONTINUOUS];

        $row = $this->block($sheet, $row, 'As duas leituras do ano', $readings);

        $row = $this->block($sheet, $row, 'Como ler a folha «Contínua Final por Domínio»', [
            ['O que é', 'A conclusão FORMAL do ano em cada domínio: a média dos resultados formais de cada unidade temporal desse domínio.'],
            ['O que entra', 'Apenas os resultados formais das unidades — os que fecham cada semestre, período ou módulo.'],
            ['O que NÃO entra', 'As fotografias intercalares não entram, porque são leituras informativas a meio do caminho e não conclusões de unidade. O desempenho acumulado também não: é a outra leitura do ano e vive na folha seguinte.'],
            ['Os pesos', 'São os configurados em «Unidades formais que entram na avaliação contínua», acima. Sem pesos declarados, todas as unidades pesam o mesmo.'],
            ['Uma unidade sem resultado', 'Fica fora da média e do seu denominador. Não conta como zero — uma unidade que o aluno não viveu não é uma unidade em que ele tenha tido nada.'],
            [ReadingVocabulary::FINAL_AVERAGE, ReadingVocabulary::FINAL_AVERAGE_EXPLANATION],
            ['Proposta final', 'O nível que sai dessa média pela escala do perfil. É uma proposta do Lapispro: o sistema propõe, o professor decide.'],
            ['Decisão final', 'O nível que o professor atribuiu ao domínio, quando ele se pronunciou. Fica vazia quando não o fez — e isso não é uma pendência.'],
            ['Menção vigente', 'A que está a valer: a decisão final quando ela existe; a proposta final quando não. Uma proposta que ninguém alterou VIGORA — não precisa de ser aprovada.'],
        ]);

        $row = $this->block($sheet, $row, 'Como ler a folha «Desempenho acumulado»', [
            ['Uma linha por unidade', 'Os pontos obtidos e a cotação que essa unidade temporal contribuiu para o domínio.'],
            ['Linha TOTAL', 'A soma das linhas acima. Pontos obtidos a dividir pela cotação, vezes cem, é o desempenho acumulado — a mesma conta que o ecrã mostra.'],
            ['Peso efetivo', 'A fatia da cotação total que uma unidade ocupa. NÃO é um peso configurado por ninguém: resulta das cotações dos elementos e muda à medida que o ano avança. É por isso que uma unidade com mais elementos pesa mais no acumulado.'],
            ['Pesos formais das unidades', 'Esses são outra coisa e vivem no bloco acima: pertencem à avaliação contínua e não têm efeito nenhum sobre o desempenho acumulado.'],
        ]);

        $this->block($sheet, $row, 'Como ler este ficheiro', [
            ['Momento formal', 'O resultado da unidade temporal. É reeditável e é o que entra na avaliação contínua.'],
            ['Momento intercalar', 'Uma fotografia informativa guardada a meio do caminho. Mostra o que era verdade nesse dia e NÃO entra na avaliação contínua.'],
            ['Apreciação vigente', 'A decisão do professor quando existe; a proposta do Lapispro quando o professor não se pronunciou.'],
            ['Origem', '«Decisão do professor» ou «Proposta do Lapispro» — a coluna diz qual das duas está a valer.'],
            ['Tendência', 'Movimento da apreciação vigente face ao momento estrutural anterior. Vazia quando não há termo de comparação.'],
            ['Célula vazia', 'Ausência de dado, nunca um zero. Um elemento por realizar não é uma classificação de zero.'],
            ['Cor de domínio', 'Identidade do domínio, nunca desempenho.'],
            ['Cor da apreciação', 'A posição do nível na escala: o mais alto verde, o mais baixo vermelho. Nunca o número que o nível tem — uma escala sem números pinta-se igual. A cor nunca é a única informação: o nível está sempre escrito ao lado.'],
        ]);

        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(86);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $lines
     */
    protected function block(Worksheet $sheet, int $row, string $title, array $lines): int
    {
        $sheet->setCellValue([1, $row], $title);
        $sheet->getStyle([1, $row])->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:B{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_FILL);
        $row++;

        foreach ($lines as [$label, $value]) {
            $this->left($sheet, 1, $row, $label);
            $this->left($sheet, 2, $row, $value);
            $row++;
        }

        return $row + 1;
    }

    // ------------------------------------------------------------- utilitários

    /**
     * @param  array<string, mixed>  $context
     * @return int a linha do cabeçalho da tabela
     */
    protected function writeTitleBlock(Worksheet $sheet, array $context, string $title): int
    {
        $sheet->setCellValue('A1', $title.' — '.(string) $context['class_label']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $lines = [
            ['Disciplina', (string) $context['subject']],
            ['Ano letivo', (string) $context['academic_year']],
            ['Escala', (string) ($context['scale_name'] ?? '—')],
            ['Exportado em', (string) $context['exported_on']],
        ];

        $row = 2;

        foreach ($lines as [$label, $value]) {
            $sheet->setCellValue([1, $row], $label);
            $sheet->getStyle([1, $row])->getFont()->setBold(true);
            $this->left($sheet, 2, $row, $value);
            $row++;
        }

        return $row + 1;
    }

    /** @param  list<string>  $headers */
    protected function headerRow(Worksheet $sheet, array $headers, int $row): void
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, $row], $header);
        }
    }

    protected function finish(
        Worksheet $sheet,
        int $headerRow,
        int $columnCount,
        int $lastRow,
        string $freezeAt = 'C',
        int $nameWidth = 30,
        bool $singleHeaderRow = false,
    ): void {
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $filterRow = $singleHeaderRow ? $headerRow : $headerRow + 1;

        $header = $sheet->getStyle("A{$headerRow}:{$lastColumn}{$filterRow}");
        $header->getFont()->setBold(true);
        $header->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);

        $sheet->getStyle("A{$headerRow}:B{$filterRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_FILL);

        if ($lastRow > $filterRow) {
            $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastRow}")
                ->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::BORDER);

            $sheet->setAutoFilter("A{$filterRow}:{$lastColumn}{$lastRow}");
        }

        // O nome do aluno à vista quando se rola para a direita, o cabeçalho
        // quando se rola para baixo — que é o que torna legível uma tabela mais
        // larga e mais alta do que um ecrã.
        $sheet->freezePane($freezeAt.($filterRow + 1));

        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth($nameWidth);

        for ($column = 3; $column <= $columnCount; $column++) {
            $sheet->getColumnDimensionByColumn($column)->setWidth(16);
        }

        $sheet->getRowDimension($headerRow)->setRowHeight(22);

        if (! $singleHeaderRow) {
            $sheet->getRowDimension($filterRow)->setRowHeight(30);
        }
    }

    /**
     * A tinta de um nível, ou nada quando ele não se consegue colocar na escala.
     *
     * A MESMA REPARTIÇÃO QUE `qualitativeToneFor` faz no browser: negativo →
     * vermelho; entre os positivos, a posição relativa reparte por âmbar (mais
     * baixo), azul (meio) e verde (mais alto). Duas implementações da mesma
     * regra é o preço de o ficheiro não ser desenhado no browser — e é por isso
     * que a regra está escrita nos dois sítios em vez de inferida em nenhum.
     *
     * @param  list<array<string, mixed>>  $bands
     */
    protected function toneFor(?string $code, ?string $label, array $bands): ?string
    {
        if ($bands === [] || ($code === null && $label === null)) {
            return null;
        }

        $band = null;
        foreach ($bands as $candidate) {
            if (($code !== null && (string) $candidate['code'] === $code)
                || ($label !== null && (string) $candidate['label'] === $label)) {
                $band = $candidate;

                break;
            }
        }

        if ($band === null) {
            return null;
        }

        if ($band['is_negative'] === true) {
            return self::TONES['red'];
        }

        $positives = array_values(array_filter($bands, fn (array $row): bool => $row['is_negative'] !== true));
        $rank = null;
        foreach ($positives as $index => $row) {
            if ((string) $row['code'] === (string) $band['code']) {
                $rank = $index;

                break;
            }
        }

        if ($rank === null) {
            return null;
        }

        $fraction = count($positives) <= 1 ? 1.0 : $rank / (count($positives) - 1);

        return match (true) {
            $fraction >= 2 / 3 => self::TONES['green'],
            $fraction >= 1 / 3 => self::TONES['blue'],
            default => self::TONES['amber'],
        };
    }

    /**
     * Pinta uma célula com a tinta de um nível, quando ela existe.
     *
     * @param  list<array<string, mixed>>  $bands
     */
    protected function paintLevel(Worksheet $sheet, int $column, int $row, ?string $code, ?string $label, array $bands): void
    {
        $tone = $this->toneFor($code, $label, $bands);

        if ($tone !== null) {
            $this->fill($sheet, $column, $row, $column, $row, $tone);
        }
    }

    protected function originLabel(?string $origin): string
    {
        return match ($origin) {
            'decided' => 'Decisão do professor',
            'proposed' => 'Proposta do Lapispro',
            default => '',
        };
    }

    /** @param  array<string, mixed>|null  $trend */
    protected function trendLabel(?array $trend): string
    {
        if ($trend === null) {
            return '';
        }

        $from = (string) ($trend['from']['code'] ?? $trend['from']['label'] ?? '');
        $to = (string) ($trend['to']['code'] ?? $trend['to']['label'] ?? '');

        // A PALAVRA PRIMEIRO, e o trajeto a seguir. Uma seta sozinha numa célula
        // de Excel não tem `title` nenhum onde se explicar (§27).
        return trim((string) $trend['label'].($from === '' ? '' : " ({$from} → {$to})"));
    }

    /** @param  array<string, mixed>  $domain */
    protected function rgb(array $domain): string
    {
        $hex = ltrim((string) ($domain['color'] ?? ''), '#');

        return preg_match('/^[0-9A-Fa-f]{6}$/', $hex) === 1 ? strtoupper($hex) : 'F3F4F6';
    }

    protected function fill(Worksheet $sheet, int $fromColumn, int $fromRow, int $toColumn, int $toRow, string $rgb, bool $soft = false): void
    {
        $range = Coordinate::stringFromColumnIndex($fromColumn).$fromRow
            .':'.Coordinate::stringFromColumnIndex($toColumn).$toRow;

        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB($soft ? $this->lighten($rgb) : $rgb);
    }

    protected function lighten(string $rgb): string
    {
        $mixed = '';

        foreach ([0, 2, 4] as $offset) {
            $channel = (int) hexdec(substr($rgb, $offset, 2));
            $mixed .= str_pad(dechex((int) round($channel + (255 - $channel) * 0.55)), 2, '0', STR_PAD_LEFT);
        }

        return strtoupper($mixed);
    }

    protected function text(Worksheet $sheet, int $column, int $row, string $value): void
    {
        if ($value === '') {
            return;
        }

        $sheet->setCellValueExplicit([$column, $row], $value, DataType::TYPE_STRING);
        $sheet->getStyle([$column, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    protected function left(Worksheet $sheet, int $column, int $row, string $value): void
    {
        if ($value === '') {
            return;
        }

        $sheet->setCellValueExplicit([$column, $row], $value, DataType::TYPE_STRING);
        $sheet->getStyle([$column, $row])->getAlignment()->setWrapText(true);
    }

    protected function percentage(Worksheet $sheet, int $column, int $row, mixed $value): void
    {
        if ($value === null || ! is_numeric($value)) {
            return;
        }

        $sheet->setCellValue([$column, $row], round((float) $value, 1));
        $sheet->getStyle([$column, $row])->getNumberFormat()->setFormatCode('0.0');
        $sheet->getStyle([$column, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /** Os bytes do ficheiro, sem passar pelo disco — ver `EvaluationSheetXlsxWriter::render()`. */
    protected function render(Spreadsheet $spreadsheet): string
    {
        if (! ob_start()) {
            throw new RuntimeException('Não foi possível preparar o ficheiro Excel do Quadro Síntese.');
        }

        try {
            (new Xlsx($spreadsheet))->save('php://output');
        } finally {
            $contents = ob_get_clean();
            $spreadsheet->disconnectWorksheets();
        }

        return $contents === false ? '' : $contents;
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
}
