<?php

namespace App\Services\Assessment\Export;

use App\Services\Assessment\DecisionScaleReader;

/**
 * O TEXTO DE CADA CÉLULA DA PAUTA, escrito uma só vez.
 *
 * O CSV e o Excel dizem o mesmo sobre cada aluno — o que o Lapispro propôs, o
 * que o professor decidiu e de onde veio cada coisa —, e diziam-no cada um com
 * a sua cópia das mesmas regras. Duas cópias de uma regra que distingue uma
 * proposta de uma decisão (§3.3) acabam por divergir, e a divergência só se vê
 * quando alguém compara dois ficheiros. Aqui está a única leitura; quem escreve
 * o ficheiro decide só a FORMA (texto, negrito, número).
 *
 * LÊ-SE O MODELO DE LEITURA, NÃO SE CALCULA NADA. Nenhum método consulta a
 * escala de hoje nem a base de dados: recebem a linha tal como a pauta (ou a
 * fotografia guardada) a trouxe, e uma chave que falte numa fotografia antiga
 * quer dizer exatamente que ninguém se pronunciou.
 */
final class EvaluationSheetCellText
{
    /**
     * O nível PROPOSTO de uma linha da pauta (um domínio ou o global): o código
     * da escala, ou a etiqueta quando não há código. Vazio sem linha.
     *
     * @param  array<string, mixed>|null  $row
     */
    public static function level(?array $row): string
    {
        if ($row === null) {
            return '';
        }

        return (string) ($row['scale_level_code'] ?? $row['scale_level_label'] ?? '');
    }

    /**
     * A decisão do professor sobre um domínio, vazia quando não existe.
     *
     * Uma fotografia guardada antes de esta decisão existir não traz estas
     * chaves, e a ausência delas é exatamente o que significa: ninguém se
     * pronunciou. O ficheiro histórico continua a dizer o que dizia.
     *
     * @param  array<string, mixed>|null  $row
     */
    public static function decidedLevel(?array $row): string
    {
        if ($row === null) {
            return '';
        }

        return (string) ($row['decided_scale_level_code'] ?? $row['decided_scale_level_label'] ?? '');
    }

    /**
     * A proposta do Lapispro para a classificação do período, na escala — nunca
     * a percentagem normalizada que a produziu, e nunca na coluna da decisão.
     *
     * @param  array<string, mixed>|null  $classification
     */
    public static function proposed(?array $classification): string
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
     * A DECISÃO DO PROFESSOR sobre a classificação do período, e só ela, com a
     * origem dita por palavras.
     *
     * O valor fica vazio enquanto ninguém decidiu — e a origem diz isso, em vez
     * de deixar uma proposta a passar por nota (§3.3).
     *
     * @param  array<string, mixed>|null  $classification
     * @return array{string, string} o valor decidido e a sua origem
     */
    public static function decided(?array $classification): array
    {
        if ($classification === null) {
            return ['', 'Sem classificação registada'];
        }

        $final = $classification['final_scale_level_code']
            ?? $classification['final_scale_level_label']
            ?? $classification['final_value'];

        if ($final !== null) {
            // Um valor decidido numa escala de percentagem lê-se como percentagem:
            // 65 é 65%, nunca o nível 65.
            $isPercentage = ($classification['final_scale_level_code'] ?? $classification['final_scale_level_label'] ?? null) === null
                && ($classification['final_value_is_percentage'] ?? false) === true;

            return [$isPercentage ? DecisionScaleReader::percentText((string) $final) : (string) $final, 'Decisão do professor'];
        }

        $proposed = $classification['proposed_scale_level_code']
            ?? $classification['proposed_scale_level_label']
            ?? $classification['proposed_value'];

        return ['', $proposed === null
            ? 'Sem classificação registada'
            : 'Proposta do Lapispro (não decidida)'];
    }
}
