<?php

namespace App\Models;

/**
 * POR QUE RAZÃO A ELIMINAÇÃO FOI SUSPENSA — classificado, nunca escrito à mão.
 *
 * Um hold é uma excepção a uma promessa de eliminação. Uma excepção dessas tem
 * de ser contável e defensável, e texto livre não é nem uma coisa nem outra:
 * ninguém consegue responder «quantos pedidos estão retidos por obrigação
 * legal?» a um campo onde cada operador escreveu a sua frase.
 *
 * A NOTA CONTINUA A EXISTIR, ao lado, opcional e interna — para o detalhe que o
 * código não carrega. Nunca sai para auditoria nem para email, e é o único
 * campo do hold que a anonimização apaga: os restantes sobrevivem como prova de
 * que a excepção foi invocada.
 */
enum RetentionHoldReason: string
{
    /** Litígio em curso ou anunciado. */
    case LegalDispute = 'legal_dispute';

    /** Investigação de fraude. */
    case FraudInvestigation = 'fraud_investigation';

    /** Obrigação legal de conservação. */
    case StatutoryObligation = 'statutory_obligation';

    /** Processo formal — disciplinar, inspectivo, regulatório. */
    case FormalProceeding = 'formal_proceeding';

    /** Nenhuma das acima, decidido por quem olhou. A nota explica. */
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LegalDispute => __('Litígio'),
            self::FraudInvestigation => __('Investigação de fraude'),
            self::StatutoryObligation => __('Obrigação legal de conservação'),
            self::FormalProceeding => __('Processo formal'),
            self::Other => __('Outra razão'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
            self::cases(),
        );
    }
}
