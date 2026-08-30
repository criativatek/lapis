<?php

namespace App\Support\Commercial;

use App\Models\Voucher;
use RuntimeException;

/**
 * O ALFABETO, A NORMALIZAÇÃO E A GERAÇÃO — num só sítio, e por isso sem duas
 * respostas.
 *
 * UM CÓDIGO É LIDO POR PESSOAS. Chega por email, por SMS, escrito num quadro de
 * uma formação, ditado ao telefone. Por isso o alfabeto é o MESMO que
 * `BankTransferReference` já usa e pela mesma razão: **sem O nem 0, sem I nem
 * 1** — os quatro caracteres que se trocam sempre. Ter dois alfabetos no mesmo
 * domínio comercial seria ter duas respostas para «que caracteres é que este
 * produto usa em códigos legíveis», e uma delas havia de ficar por actualizar.
 *
 * DUAS FORMAS, E AS DUAS SÃO GUARDADAS:
 *
 *  - **Apresentação** — `LPRO-A3K9-2XQ7`. Com hífens, porque três grupos de
 *    quatro dizem-se ao telefone e um bloco de doze não.
 *  - **Normalizada** — `LPROA3K92XQ7`. Maiúsculas, só letras e dígitos. É esta
 *    que é `UNIQUE` e é por esta que se procura.
 *
 * A REGRA DE COMPARAÇÃO, DITA UMA VEZ: passa-se a maiúsculas e deita-se fora
 * tudo o que não seja `A-Z` ou `0-9`. Consequência deliberada: hífens, espaços e
 * a caixa das letras são IRRELEVANTES para a comparação, e quem escreve
 * `lpro a3k9 2xq7` acerta. Consequência igualmente deliberada: um `O` escrito
 * onde devia estar um `0` NÃO é corrigido — corrigir seria adivinhar, e adivinhar
 * num código é aceitar um código que ninguém emitiu. O alfabeto resolve o
 * problema à nascença em vez de o resolver por inferência na leitura.
 *
 * NUNCA UM ID INCREMENTAL. Um código público derivado de `vouchers.id` seria
 * enumerável por construção: quem tivesse um código teria todos os outros. Aqui,
 * doze caracteres em 32 dão ~1,15 × 10^18 combinações, e a unicidade continua a
 * ser verificada contra a base de dados porque «improvável» e «impossível» não
 * são a mesma coisa — o mesmo raciocínio que a referência de transferência já
 * documenta.
 */
final class VoucherCode
{
    /** Sem O/0 nem I/1. O mesmo de `BankTransferReference`, deliberadamente. */
    private const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Três grupos de quatro: doze caracteres significativos. */
    private const GRUPOS = 3;

    private const TAMANHO_DO_GRUPO = 4;

    /**
     * O maior código que a fronteira aceita, em caracteres normalizados.
     *
     * Generoso em relação aos doze que se geram, porque um código pode vir de
     * uma campanha antiga ou de um parceiro com o seu próprio formato — e
     * apertado o suficiente para que ninguém tente usar o campo como um saco de
     * texto. A coluna aguenta 64; isto recusa antes de lá chegar.
     */
    public const MAX_NORMALIZED_LENGTH = 32;

    /**
     * O menor código que faz sentido aceitar.
     *
     * Quatro caracteres num alfabeto de 32 são um milhão de combinações — já
     * demasiado poucas para um código público, mas é o piso abaixo do qual um
     * campo de texto deixa de ser um código e passa a ser um engano de escrita.
     */
    public const MIN_NORMALIZED_LENGTH = 4;

    /**
     * A forma pela qual se compara. A ÚNICA.
     *
     * Nada nesta aplicação deve comparar códigos de outra maneira: uma segunda
     * regra de normalização é uma segunda resposta para «é este o mesmo
     * código?», e as duas hão-de divergir no dia em que uma delas aprender a
     * aceitar pontos.
     */
    public static function normalize(string $raw): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($raw)));
    }

    /**
     * A forma pela qual se apresenta: grupos de quatro, separados por hífen.
     *
     * Aplicada à forma NORMALIZADA, para que um código gravado sem hífens ou com
     * hífens em sítios diferentes se leia sempre da mesma maneira. Um código
     * cujo comprimento não seja múltiplo de quatro simplesmente termina com um
     * grupo mais curto — não se enche com nada, porque um caractere inventado
     * num código é pior do que um grupo torto.
     */
    public static function present(string $raw): string
    {
        $normalized = self::normalize($raw);

        if ($normalized === '') {
            return '';
        }

        return implode('-', str_split($normalized, self::TAMANHO_DO_GRUPO));
    }

    /**
     * Se este texto tem sequer a forma de um código.
     *
     * Chamado ANTES de tocar na base de dados, e é essa a sua utilidade: um
     * campo vazio, um parágrafo colado por engano ou um `<script>` nunca chegam
     * a ser uma consulta. Não diz nada sobre o código existir — isso é outra
     * pergunta, e é feita a outro sítio.
     */
    public static function isWellFormed(string $raw): bool
    {
        $normalized = self::normalize($raw);
        $length = strlen($normalized);

        return $length >= self::MIN_NORMALIZED_LENGTH && $length <= self::MAX_NORMALIZED_LENGTH;
    }

    /**
     * Um código novo, único, com o prefixo da instalação.
     *
     * O prefixo é o mesmo de `billing.reference_prefix` porque é a mesma marca a
     * falar — quem recebe `LPRO-...` num email reconhece-o do mesmo sítio de
     * onde reconhece a referência de transferência. Não participa na unicidade
     * (é constante), e por isso não conta para a entropia.
     *
     * @throws RuntimeException se vinte sorteios seguidos colidirem, o que não
     *                          acontece por acaso num espaço de 10^18: ou o
     *                          alfabeto encolheu ou o gerador está partido, e
     *                          falhar é melhor do que devolver um código repetido.
     */
    public static function generate(): string
    {
        $prefixo = mb_strtoupper((string) config('billing.reference_prefix'));

        for ($tentativa = 0; $tentativa < 20; $tentativa++) {
            $candidato = $prefixo.'-'.self::sorteia();

            $existe = Voucher::query()
                ->where('normalized_code', self::normalize($candidato))
                ->exists();

            if (! $existe) {
                return $candidato;
            }
        }

        throw new RuntimeException('Não foi possível gerar um código de voucher único.');
    }

    private static function sorteia(): string
    {
        $grupos = [];

        for ($g = 0; $g < self::GRUPOS; $g++) {
            $grupo = '';

            for ($i = 0; $i < self::TAMANHO_DO_GRUPO; $i++) {
                $grupo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
            }

            $grupos[] = $grupo;
        }

        return implode('-', $grupos);
    }
}
