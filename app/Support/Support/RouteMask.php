<?php

namespace App\Support\Support;

/**
 * Uma rota reduzida ao que responde «onde é que a pessoa estava».
 *
 * `technical_route` existe para o operador saber em que ecrã alguém tropeçou, e
 * o nome do ecrã responde a isso por inteiro. Os identificadores que a rota
 * carrega pelo meio não acrescentam nada a essa resposta — e são, esses sim, a
 * identificação de uma turma ou de uma criança concreta. Numa aplicação onde
 * todas as URLs expõem ULIDs (§11.2 do CLAUDE.md), guardar a rota em bruto é
 * guardar um apontador para o aluno sobre quem o pedido é.
 *
 * A LINHA DOS SEIS ALGARISMOS É EMPRESTADA, NÃO INVENTADA. É a mesma de
 * `AiPayloadSanitizer`: abaixo de seis, um número não é identificador nenhum
 * — é um ano, uma página, um período —, e acima de seis não é mais nada. Duas
 * regras diferentes para a mesma pergunta seria uma delas a estar errada.
 *
 * O QUE FICA DE FORA POR DESENHO. Um segmento que seja uma palavra fica como
 * está, ainda que pareça um código: uma máscara que devolvesse `/:id/:id` teria
 * apagado a única informação pela qual esta coluna existe. O teste
 * `TechnicalRouteMaskingTest` afirma as duas metades — o que desaparece e o que
 * tem de sobreviver — porque uma máscara gulosa falha em silêncio.
 *
 * VIVE NO SERVIDOR. O ecrã também mascara, e isso é conveniência para quem vê o
 * que vai enviar; o código que decide o que se remove não pode ser o que viaja
 * no browser de quem envia.
 */
class RouteMask
{
    /** O que substitui um segmento que identifica alguma coisa. */
    public const PLACEHOLDER = ':id';

    /** ULID de Crockford: 26 caracteres, sem I, L, O nem U. */
    protected const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/i';

    protected const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Ver o docblock: a mesma fronteira que o `AiPayloadSanitizer` usa. */
    protected const LONG_NUMBER = '/^\d{6,}$/';

    public static function apply(?string $route): ?string
    {
        if ($route === null || trim($route) === '') {
            return null;
        }

        // A query string e o fragmento nunca entram: é onde vivem os tokens, os
        // filtros e o que mais alguém tenha colado na barra de endereço.
        $path = (string) preg_replace('/[?#].*$/', '', trim($route));

        $masked = implode('/', array_map(
            static fn (string $segment): string => self::identifies($segment)
                ? self::PLACEHOLDER
                : $segment,
            explode('/', $path),
        ));

        return mb_substr($masked, 0, 200);
    }

    protected static function identifies(string $segment): bool
    {
        return preg_match(self::ULID, $segment) === 1
            || preg_match(self::UUID, $segment) === 1
            || preg_match(self::LONG_NUMBER, $segment) === 1;
    }
}
