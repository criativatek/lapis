<?php

namespace App\Support\Assessment;

/**
 * AS REGRAS DE CÁLCULO QUE O MOTOR CUMPRE — a lista, num sítio só.
 *
 * As colunas de `assessment_profile_versions` aceitam mais valores do que o
 * `CalculationEngine` implementa: o `CHECK` da base de dados guarda o conjunto
 * aprovado no ADR-0004, mas o motor só sabe uma combinação — resultado do
 * período por média ponderada dos domínios, acumulado sobre todos os elementos
 * válidos do ano, e arredondamento uma única vez na proposta final.
 *
 * Implementar os outros modos não é trabalho de programação: é decidir uma
 * regra pedagógica, e essas não se inventam (§1). Até haver decisão, a
 * aplicação recusa em vez de calcular por uma regra e dizer que calculou por
 * outra — e recusa nos DOIS caminhos por onde uma versão pode ficar activa:
 *
 *  - `ActivateProfileVersion`, quando o professor ativa um rascunho;
 *  - `ValidateBackupPayload`, quando uma importação traz uma versão já ativa
 *    de outra instalação (essa nunca passa pela activação).
 *
 * NULL não é uma regra diferente: é uma coluna por preencher, e o cálculo
 * aplica-lhe o valor por omissão do ADR-0004.
 *
 * No dia em que um modo for aprovado e implementado, sai daqui uma linha.
 */
final class SupportedCalculationRules
{
    /** @var array<string, string> */
    public const RULES = [
        'period_result_mode' => 'weighted_domain_average',
        'accumulated_mode' => 'all_valid_year_elements',
        'rounding_stage' => 'final_only',
    ];

    /**
     * O primeiro campo cuja regra o motor não cumpre, ou null se estiver tudo bem.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{field: string, value: string, supported: string}|null
     */
    public static function firstUnsupported(array $attributes): ?array
    {
        foreach (self::RULES as $field => $supported) {
            $value = $attributes[$field] ?? null;

            if ($value === null || $value === '' || $value === $supported) {
                continue;
            }

            return ['field' => $field, 'value' => (string) $value, 'supported' => $supported];
        }

        return null;
    }
}
