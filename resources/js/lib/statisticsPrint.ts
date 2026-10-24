/**
 * A decisão de papel do Acompanhamento da Turma impresso, em função pura.
 *
 * O jsdom não mede nem pagina, por isso a parte que decide (que escala) vive
 * aqui, onde se testa com números, e a página só mede o DOM e aplica o resultado.
 * A ideia é a do Quadro Síntese (`quadroPrint`), reduzida ao que esta página
 * precisa: papel A4 vertical, e uma única tabela larga — o mapa da turma, que
 * cresce com o número de domínios.
 */

/** A largura do A4 vertical e as margens laterais do `PrintableDocument` (16 mm). */
const PORTRAIT_WIDTH_MM = 210;
const SIDE_MARGIN_MM = 16;

const PX_PER_MM = 96 / 25.4;

/**
 * Abaixo disto o texto do mapa já não se lê com conforto no papel. A escala
 * continua a encolher até caber (uma coluna cortada é pior do que uma letra
 * pequena), mas a página avisa o professor.
 */
export const LEGIBLE_SCALE = 0.6;

/** Só impede o zero: uma coluna cortada é pior do que uma letra minúscula. */
const HARD_MIN_SCALE = 0.05;

/** A largura útil da folha A4 vertical, em pixéis CSS. */
export function usableWidthPx(): number {
    return (PORTRAIT_WIDTH_MM - 2 * SIDE_MARGIN_MM) * PX_PER_MM;
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

/** O aviso do ecrã quando o mapa só cabe abaixo do piso de legibilidade. */
export function illegibleScaleNotice(scale: number): string {
    const percent = Math.round(scale * 100);

    return `O mapa da turma é muito largo: imprime-se a ${percent} % do tamanho para caber na folha, e a letra pode ficar pequena.`;
}
