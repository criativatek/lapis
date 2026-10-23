/**
 * As duas decisões de papel do Quadro Síntese impresso, em funções puras.
 *
 * O jsdom não mede nem pagina, por isso a parte que decide (que papel, que
 * escala) vive aqui, onde se testa com números, e a página só mede o DOM e
 * aplica o resultado.
 */

export type Paper = 'A4' | 'A3';

export type SummaryView = 'moments' | 'domains';

/** Larguras do papel em paisagem, em mm. A orientação é sempre horizontal. */
const LANDSCAPE_WIDTH_MM: Record<Paper, number> = { A4: 297, A3: 420 };

/** A margem compacta do `PrintableDocument` (10 mm de cada lado). */
const COMPACT_MARGIN_MM = 10;

const PX_PER_MM = 96 / 25.4;

/**
 * Acima disto, uma grelha «Ao longo do ano» deixa de ler-se bem num A4: com
 * 14 colunas de dados já cada uma fica com pouco mais de 18 mm.
 */
export const A4_MAX_COLUMNS = 14;

/**
 * Abaixo disto o texto do Quadro já não se lê com conforto no papel; a escala
 * continua a encolher até caber (uma coluna cortada é pior do que uma letra
 * pequena), mas a página avisa o professor.
 */
export const LEGIBLE_SCALE = 0.5;

/** Só impede o zero: uma coluna cortada é pior do que uma letra minúscula. */
const HARD_MIN_SCALE = 0.05;

/**
 * Quantas colunas de dados a vista «Ao longo do ano» tem, a partir dos dados e
 * não do DOM: cada momento ocupa a apreciação e a tendência (mais o quantitativo
 * quando visível) e o indicador formal fecha a linha. Os momentos abertos
 * acrescentam colunas por domínio, mas isso é estado do ecrã que a escala trata.
 */
export function momentsColumnCount(moments: number, showQuantitative: boolean): number {
    const perBlock = showQuantitative ? 3 : 2;

    return 1 + moments * perBlock + perBlock;
}

/**
 * O papel recomendado. A vista «Por domínio» é sempre larga (cada domínio dá
 * colunas por unidade temporal), por isso vai para A3; «Ao longo do ano» fica em
 * A4 enquanto couber em `A4_MAX_COLUMNS` colunas.
 */
export function recommendedPaper(view: SummaryView, columns: number): Paper {
    if (view === 'domains') {
        return 'A3';
    }

    return columns > A4_MAX_COLUMNS ? 'A3' : 'A4';
}

/** A largura útil da folha em pixéis CSS, em paisagem e com a margem compacta. */
export function usableWidthPx(paper: Paper): number {
    return (LANDSCAPE_WIDTH_MM[paper] - 2 * COMPACT_MARGIN_MM) * PX_PER_MM;
}

export type PrintScale = {
    /** O fator de `zoom` a aplicar à tabela: 1 quando já cabe. */
    scale: number;
    /** Se o resultado ainda se lê bem; falso quando se encolheu abaixo do piso. */
    legible: boolean;
};

/**
 * A escala que faz a tabela caber na largura útil. Nunca aumenta (1 é o teto) e
 * nunca devolve zero ou NaN, mesmo com medições inválidas.
 */
export function printScale(tableWidth: number, usableWidth: number): PrintScale {
    if (!(tableWidth > 0) || !(usableWidth > 0) || tableWidth <= usableWidth) {
        return { scale: 1, legible: true };
    }

    // Arredonda por baixo ao milésimo: arredondar para cima deixaria a tabela
    // 1 px mais larga do que a folha e a última coluna seria cortada.
    const fitted = Math.floor((usableWidth / tableWidth) * 1000) / 1000;
    const scale = Math.max(HARD_MIN_SCALE, fitted);

    return { scale, legible: scale >= LEGIBLE_SCALE };
}

/**
 * O aviso do ecrã quando a tabela só cabe abaixo do piso de legibilidade. Vive
 * aqui, e não na página, porque a página não faz aritmética (a percentagem é
 * apresentação, mas a regra do Quadro é «nada se calcula no browser»).
 */
export function illegibleScaleNotice(scale: number): string {
    const percent = Math.round(scale * 100);

    return `A tabela é muito larga: imprime-se a ${percent} % do tamanho para caber na folha. Para uma leitura melhor, desligue o desempenho acumulado ou os quantitativos.`;
}
