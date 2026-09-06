<?php

namespace App\Models;

use App\Support\Export\InovarLevelOption;
use Carbon\CarbonInterface;

/**
 * OS DOIS MOMENTOS PEDAGÓGICOS DE CADA UNIDADE TEMPORAL.
 *
 * Um período tem sempre dois momentos que a escola reconhece: o INTERCALAR, a
 * meio do caminho, e o FINAL, que o fecha. São os dois que aparecem no topo da
 * Pauta, nesta ordem, para cada semestre, período ou módulo que o ano letivo
 * tiver configurado.
 *
 * NÃO É UMA SEGUNDA NOÇÃO DE «INTERCALAR». As «Avaliações intercalares» — a
 * fotografia estatística de uma turma numa data — continuam a ser o que eram e
 * não são tocadas por isto. O que aqui se nomeia é outra coisa: QUAL DOS DOIS
 * MOMENTOS a pauta está a preparar. Já existia implicitamente, lida das datas
 * do período por `InovarLevelOption::includedByDefault`; o que faltava era
 * navegação para ela.
 *
 * NADA AQUI MUDA UM NÚMERO. Os dois momentos leem exatamente a mesma pauta —
 * o estado avaliativo de hoje, que é o único que existe. O que muda é o que se
 * está a preparar: o título sugerido para a fotografia, o que «Preparar fecho»
 * espera encontrar, e se a grelha do Inovar leva o nível por omissão.
 *
 * O RÓTULO DERIVA DA CONFIGURAÇÃO, NUNCA DE UMA PALAVRA ESCRITA AQUI. «1.º
 * Semestre» e «Intercalar 1.º Semestre» saem ambos do `label` que a escola deu
 * ao período; uma escola que chame «Módulo 3» ao seu vê «Intercalar Módulo 3»
 * sem que uma linha de código tenha de saber o que é um semestre (§6).
 */
enum SheetMomentKind: string
{
    case Interim = 'interim';

    case Final = 'final';

    /**
     * O que o topo da pauta escreve neste separador.
     *
     * O momento final é chamado pelo NOME DO PERÍODO e por mais nada: é o
     * momento por omissão, é o que a pauta sempre mostrou, e acrescentar-lhe
     * «Final» faria dois separadores igualmente adjectivados onde só um deles
     * precisa de o ser.
     */
    public function tabLabel(AcademicPeriod $period): string
    {
        return match ($this) {
            self::Interim => 'Intercalar '.$period->label,
            self::Final => (string) $period->label,
        };
    }

    /**
     * A frase que diz de que momento se trata, para quem lê fora da barra de
     * separadores — o cabeçalho do ecrã, a folha impressa, um leitor de ecrã.
     */
    public function momentLabel(AcademicPeriod $period): string
    {
        return match ($this) {
            self::Interim => 'Momento intercalar do '.$period->label,
            self::Final => $period->kind->label().' — '.$period->label,
        };
    }

    /**
     * Se este momento FECHA a unidade temporal.
     *
     * Um momento intercalar nunca fecha nada — é essa a sua definição, e não
     * uma leitura de datas. Um momento final fecha quando o período
     * efetivamente chegou ao seu próprio fim: enquanto ele corre, o separador
     * do fecho existe para se poder preparar o fecho, não para tratar cada
     * classificação por tomar como uma falta (§7).
     *
     * A pergunta sobre as datas continua a ser respondida onde já era, por
     * `InovarLevelOption` — a única regra aprovada que o produto tem para ela.
     */
    public function closes(AcademicPeriod $period, CarbonInterface $now): bool
    {
        return $this === self::Final && InovarLevelOption::includedByDefault($period, $now);
    }

    /**
     * O momento pedido, com o final como resposta a tudo o que não seja
     * explicitamente o intercalar.
     *
     * Uma palavra que não reconhecemos não é meio caminho para nada: é o
     * momento por omissão, que é também o que qualquer ligação antiga para a
     * pauta continua a abrir.
     */
    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Final;
    }

    /**
     * Os momentos de uma unidade temporal, na ordem em que se vivem: primeiro
     * o intercalar, depois o que a fecha.
     *
     * @return list<self>
     */
    public static function inOrder(): array
    {
        return [self::Interim, self::Final];
    }
}
