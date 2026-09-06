<?php

namespace App\Support\Export;

use Illuminate\Support\Str;

/**
 * O NOME DE UMA PESSOA, REDUZIDO AO QUE PODE SER COMPARADO SEM RISCO.
 *
 * Duas listas da mesma turma escrevem o mesmo aluno de maneiras diferentes.
 * «Álvaro Manuel Simões» numa, «Alvaro Simões» noutra: acentos que se perderam
 * num export, um nome do meio que uma das listas não traz, dois espaços onde
 * devia haver um. São a mesma pessoa, e um sistema que exigisse a string
 * idêntica obrigaria o professor a corrigir à mão trinta linhas por causa de
 * um acento.
 *
 * O QUE SE COMPARA SÃO O PRIMEIRO E O ÚLTIMO NOME, E MAIS NADA. É a regra
 * aprovada (§25): os nomes do meio podem existir só de um lado, estar
 * abreviados ou faltar, e nada disso põe em causa a identidade. O primeiro e o
 * último são os que qualquer secretaria usa para nomear uma pessoa, e são os
 * únicos que uma lista completa nunca omite.
 *
 * NÃO HÁ AQUI APROXIMAÇÃO NENHUMA. «Martins» e «Martin» não são o mesmo nome, e
 * este ficheiro não tem — nem pode ter — uma distância de edição que os
 * aproxime: uma nota escrita no aluno errado não é um erro que alguém apanhe a
 * ler, e a única coisa pior do que não corresponder é corresponder mal em
 * silêncio (§26). O que existe é normalização — acentos, maiúsculas, hífenes,
 * apóstrofos, espaços — e a normalização não muda que nome é.
 *
 * AS PARTÍCULAS SÃO DESCARTADAS antes de se olhar para as pontas. «Ana de
 * Sousa» tem «Sousa» como último nome, não «de»; sem isto, «Ana de Sousa» e
 * «Ana Sousa» seriam duas pessoas diferentes por causa de uma preposição.
 */
final readonly class PersonNameKey
{
    /**
     * Partículas que ligam nomes e não são nomes. Deliberadamente curta e
     * fechada: cada entrada aqui é uma palavra que deixa de poder ser o último
     * nome de alguém, e essa é uma decisão a tomar uma de cada vez.
     *
     * @var list<string>
     */
    private const PARTICLES = ['de', 'do', 'da', 'dos', 'das', 'e', 'del', 'la', 'van', 'von', 'di'];

    /**
     * @param  list<string>  $parts  o nome inteiro, normalizado e sem partículas
     */
    private function __construct(
        public string $first,
        public string $last,
        public array $parts,
    ) {}

    /**
     * Null quando não há nome nenhum de que falar — uma linha em branco não
     * corresponde a ninguém, e fingir que corresponde é o erro que isto evita.
     */
    public static function for(?string $name): ?self
    {
        $parts = self::split($name);

        if ($parts === []) {
            return null;
        }

        return new self($parts[0], $parts[count($parts) - 1], $parts);
    }

    /**
     * A chave por que dois nomes se comparam automaticamente: primeiro e
     * último, e nada do meio.
     */
    public function key(): string
    {
        return $this->first.' '.$this->last;
    }

    /** Se estes dois nomes são, com segurança, da mesma pessoa. */
    public function matches(self $other): bool
    {
        return $this->first === $other->first && $this->last === $other->last;
    }

    /** Um nome de uma só palavra — o primeiro e o último são a mesma. */
    public function isSingleWord(): bool
    {
        return count($this->parts) === 1;
    }

    /**
     * O nome partido em palavras comparáveis.
     *
     * `Str::ascii` trata os acentos; o resto — hífenes, apóstrofos, pontos de
     * abreviatura — vira espaço, porque «Maria-João» e «Maria João» são a mesma
     * pessoa escrita por duas secretarias diferentes.
     *
     * @return list<string>
     */
    private static function split(?string $name): array
    {
        if ($name === null) {
            return [];
        }

        $ascii = Str::of($name)->ascii()->lower()->value();
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';

        $parts = array_values(array_filter(
            explode(' ', trim(preg_replace('/\s+/', ' ', $ascii) ?? '')),
            static fn (string $part): bool => $part !== '',
        ));

        $withoutParticles = array_values(array_filter(
            $parts,
            static fn (string $part): bool => ! in_array($part, self::PARTICLES, true),
        ));

        // Um nome feito só de partículas não é um nome, mas também não é nada:
        // devolve-se o que lá estava em vez de o transformar em vazio.
        return $withoutParticles === [] ? $parts : $withoutParticles;
    }
}
