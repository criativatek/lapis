<?php

namespace App\Support\Assessment;

/**
 * AS PALAVRAS DA COBERTURA — um sítio só, para os vários ecrãs que falam dela.
 *
 * A cobertura de um resultado tem TRÊS ESTADOS, e confundir dois deles é dizer
 * a um professor uma coisa que não aconteceu:
 *
 *  - COMPLETA — todos os elementos previstos foram realizados. Não há aviso
 *    nenhum a dar, e não dar aviso nenhum é a mensagem.
 *  - PARCIAL — HOUVE avaliação, e o resultado existe, mas assenta em menos do
 *    que estava previsto. É um resultado provisório, não um resultado mau.
 *  - SEM ELEMENTOS — não houve avaliação de todo. Não há resultado, e por isso
 *    não há nada de que a cobertura possa ser parcial.
 *
 * A FRASE DA COBERTURA PARCIAL NÃO PODE APARECER NO TERCEIRO ESTADO. «Nem todos
 * os elementos previstos foram avaliados» lida sobre um aluno que não teve
 * avaliação nenhuma dá a entender que teve alguma — e é justamente essa
 * confusão que esta classe existe para tornar impossível: o estado decide a
 * frase, e o estado decide-se num único sítio.
 *
 * O ESTADO NÃO É RE-DERIVADO AQUI. Quem decide que um resultado tem cobertura
 * parcial é o motor de cálculo, que levanta a bandeira (§13.4); esta classe
 * recebe a bandeira e a existência (ou não) de um valor, e devolve palavras. É
 * exatamente a mesma leitura que o ⚠ de Resultados faz no browser — «há valor,
 * logo é parcial; não há valor, logo não houve avaliação» —, e é por isso que
 * as duas nunca podem discordar.
 */
final class CoverageWording
{
    public const COMPLETE = 'complete';

    public const PARTIAL = 'partial';

    public const NONE = 'none';

    /**
     * Em que dos três estados está este resultado.
     *
     * @return self::COMPLETE|self::PARTIAL|self::NONE
     */
    public static function state(bool $hasCoverageWarning, bool $hasValue): string
    {
        if (! $hasCoverageWarning) {
            return self::COMPLETE;
        }

        return $hasValue ? self::PARTIAL : self::NONE;
    }

    /**
     * O título curto do estado — o mesmo que o ⚠ mostra no ecrã.
     */
    public static function heading(string $state): string
    {
        return match ($state) {
            self::PARTIAL => 'Cobertura parcial',
            self::NONE => 'Sem elementos avaliados',
            default => 'Cobertura completa',
        };
    }

    /**
     * A frase da COBERTURA PARCIAL: houve avaliação, e ainda assim falta o que
     * estava previsto.
     *
     * A concessiva («embora…») é o ponto todo da frase. Sem ela, «nem todos os
     * elementos previstos foram realizados» lê-se como uma queixa sobre o
     * aluno; com ela, lê-se como o que é — uma nota sobre a evidência
     * disponível, não sobre quem foi avaliado.
     *
     * SEM GÉNERO. «o/a aluno(a) … avaliado(a)» resolve o problema escrevendo-o
     * na frase; esta formulação evita-o, e é a que o resto do produto usa
     * quando fala de uma pessoa cujo género não tem registo nenhum que o diga.
     *
     * @param  'domain'|'overall'  $scope
     */
    public static function partial(string $scope = 'domain'): string
    {
        return $scope === 'overall'
            ? 'Embora tenha havido avaliação neste momento, nem todos os elementos previstos foram realizados.'
            : 'Embora tenha havido avaliação neste domínio, nem todos os elementos previstos foram realizados.';
    }

    /**
     * A frase da AUSÊNCIA DE COBERTURA. Diz que não houve avaliação, e não
     * finge que houve — e não é um zero, nem se aproxima de um (§13.3).
     *
     * @param  'domain'|'overall'  $scope
     */
    public static function none(string $scope = 'domain'): string
    {
        return $scope === 'overall'
            ? 'Ainda não há elementos avaliados neste momento.'
            : 'Ainda não há elementos avaliados neste domínio.';
    }

    /**
     * A frase deste estado, seja ele qual for. Vazia quando não há nada a
     * assinalar — uma cobertura completa não precisa de ser anunciada.
     *
     * @param  'domain'|'overall'  $scope
     */
    public static function sentence(string $state, string $scope = 'domain'): string
    {
        return match ($state) {
            self::PARTIAL => self::partial($scope),
            self::NONE => self::none($scope),
            default => '',
        };
    }
}
