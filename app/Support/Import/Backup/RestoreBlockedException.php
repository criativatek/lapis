<?php

namespace App\Support\Import\Backup;

use RuntimeException;

/**
 * Um restauro RECUSADO antes de escrever o que quer que seja, porque o plano
 * traz motivos bloqueantes (hoje: números de lição que colidem com aulas que o
 * destino já tem). Tipo próprio, como `SelfAssessmentReadOnlyException`: não é
 * uma falha do restauro, por isso a importação NÃO é marcada como falhada nem o
 * ficheiro carregado se perde — continua confirmável depois de o professor
 * resolver o que a pré-visualização aponta.
 */
final class RestoreBlockedException extends RuntimeException
{
    /**
     * @param  list<string>  $reasons  as mensagens do plano, só datas, números e rótulos de turma
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(self::summarise($reasons));
    }

    /**
     * @param  list<string>  $reasons
     */
    private static function summarise(array $reasons): string
    {
        $first = $reasons[0] ?? '';
        $rest = count($reasons) - 1;

        return (string) __('O restauro não foi feito e nada foi gravado.').' '.$first
            .($rest > 0 ? ' '.(string) __('E mais :count ponto(s) a corrigir; a pré-visualização mostra-os todos.', ['count' => (string) $rest]) : '');
    }
}
