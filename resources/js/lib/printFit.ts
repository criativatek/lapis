/**
 * Ajuste de uma tabela impressa a UMA página, em funções puras.
 *
 * O jsdom não mede nem pagina; por isso a decisão (que escala, quantas páginas)
 * vive aqui, onde se testa com números, e a página só mede o DOM e aplica o
 * resultado numa variável CSS lida pelo `PrintableDocument`.
 *
 * Generaliza `quadroPrint.ts` e `statisticsPrint.ts` (só largura) para largura E
 * altura, com um piso de legibilidade em pontos e não em percentagem.
 */

export type Paper = 'A4' | 'A3';
export type Orientation = 'portrait' | 'landscape';

/** Lados do papel em mm: o curto e o longo. */
export const PAPER_MM: Record<Paper, { short: number; long: number }> = {
    A4: { short: 210, long: 297 },
    A3: { short: 297, long: 420 },
};

/** A margem compacta do `PrintableDocument` (10 mm de cada lado). */
export const COMPACT_MARGIN_MM = 10;

export const PX_PER_MM = 96 / 25.4;

/** A letra da tabela compacta antes de qualquer escala. */
export const BASE_FONT_PT = 7.5;

/** Abaixo disto o papel deixa de se ler; a escala nunca desce mais. */
export const FLOOR_FONT_PT = 6;

export const FLOOR_SCALE = FLOOR_FONT_PT / BASE_FONT_PT;

export type UsableArea = { width: number; height: number };

/** A área útil da folha em píxeis CSS, com a margem compacta nos quatro lados. */
export function usableAreaPx(paper: Paper, orientation: Orientation): UsableArea {
    const { short, long } = PAPER_MM[paper];
    const widthMm = (orientation === 'landscape' ? long : short) - 2 * COMPACT_MARGIN_MM;
    const heightMm = (orientation === 'landscape' ? short : long) - 2 * COMPACT_MARGIN_MM;

    return { width: widthMm * PX_PER_MM, height: heightMm * PX_PER_MM };
}

export type FitInput = {
    /** Largura natural da tabela (com a sua legenda), em px. */
    tableWidth: number;
    /** Altura natural da tabela (com a sua legenda), em px. */
    tableHeight: number;
    /** Altura do cabeçalho do documento que fica acima da tabela na primeira página, em px. */
    headerHeight: number;
    usable: UsableArea;
};

export type FitResult = {
    /** O que se aplica: nunca acima de 1, nunca abaixo de `FLOOR_SCALE`. */
    scale: number;
    /** Verdadeiro quando, com esta escala, a tabela cabe em altura na primeira página. */
    fitsOnePage: boolean;
    /** Páginas estimadas quando não cabe; 1 quando cabe. */
    pages: number;
};

const ONE_PAGE: FitResult = { scale: 1, fitsOnePage: true, pages: 1 };

function positive(value: number): boolean {
    return Number.isFinite(value) && value > 0;
}

/** Arredonda por baixo ao milésimo: por cima deixaria a tabela 1 px mais larga do que a folha. */
function floorScale(value: number): number {
    return Math.floor(value * 1000) / 1000;
}

/**
 * A escala que faz a tabela caber em largura e em altura. Quando mesmo o piso
 * não chega, fixa-se no piso e as linhas seguem para as páginas seguintes.
 */
export function fitToPage(input: FitInput): FitResult {
    const { tableWidth, tableHeight, headerHeight, usable } = input;

    if (!positive(tableWidth) || !positive(tableHeight) || !positive(usable.width) || !positive(usable.height)) {
        return ONE_PAGE;
    }

    const header = Number.isFinite(headerHeight) && headerHeight > 0 ? headerHeight : 0;
    const roomHeight = usable.height - header;

    if (!positive(roomHeight)) {
        return ONE_PAGE;
    }

    const byWidth = tableWidth <= usable.width ? 1 : floorScale(usable.width / tableWidth);
    const byHeight = tableHeight <= roomHeight ? 1 : floorScale(roomHeight / tableHeight);
    const wanted = Math.min(1, byWidth, byHeight);

    if (wanted >= FLOOR_SCALE) {
        return { scale: wanted, fitsOnePage: true, pages: 1 };
    }

    const scaledHeight = tableHeight * FLOOR_SCALE;

    return {
        scale: FLOOR_SCALE,
        fitsOnePage: false,
        pages: Math.max(2, Math.ceil(scaledHeight / roomHeight)),
    };
}

/** O aviso do ecrã (nunca do papel); `null` quando cabe a 100 %. */
export function fitNotice(result: FitResult): string | null {
    if (!result.fitsOnePage) {
        return `A ${FLOOR_FONT_PT} pt ainda não cabe numa página: sai em ${result.pages} páginas.`;
    }

    if (result.scale >= 1) {
        return null;
    }

    return `Imprime-se a ${Math.round(result.scale * 100)} % para caber numa página.`;
}
