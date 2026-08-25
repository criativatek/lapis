<?php

namespace App\Domain\Import\AcademicCalendar;

/**
 * Uma data com nome que o documento marca e que esta aplicação NÃO SABE
 * CLASSIFICAR — hoje, na prática, os «Fim 9.º ano», «Fim 5/6/7/8.º» e «Fim
 * Pré/1.ºCiclo» que os calendários reais escrevem na coluna de junho.
 *
 * NEM SE DEITA FORA NEM SE FORÇA NUMA GAVETA. Não é uma exceção letiva — não diz
 * que naquele dia não há aula, diz que a partir dali uma coorte acabou o ano —,
 * e não é o `ends_on` de um AcademicPeriod, porque são três datas para um campo
 * só e a aplicação não modela coortes. Fingir qualquer uma das duas coisas era
 * escrever no calendário uma afirmação que o documento não faz.
 *
 * Fica portanto o que é honesto: uma data com um nome, proposta como
 * CalendarEvent do tipo «Data relevante» (valor interno `other`), SEMPRE POR
 * CONFIRMAR, com a explicação à vista. O professor aceita ou rejeita; nenhum dos
 * dois é o que acontece por omissão.
 */
final readonly class ParsedCalendarMarker
{
    /**
     * @param  string  $title  a coorte escrita por extenso — «Fim das atividades
     *                         letivas — 9.º ano» —, normalizada pelo
     *                         CohortMarkerTitle a partir da abreviatura da
     *                         célula, que fica guardada em `$rawText`
     * @param  string  $date  «Y-m-d»
     * @param  string  $rawText  a célula tal como está escrita no ficheiro
     */
    public function __construct(
        public string $title,
        public string $date,
        public string $rawText,
    ) {}
}
