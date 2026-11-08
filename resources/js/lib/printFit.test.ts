import { describe, expect, it } from 'vitest';
import { BASE_FONT_PT, FLOOR_FONT_PT, FLOOR_SCALE, SAFETY_PX, fitNotice, fitToPage, usableAreaPx } from './printFit';

const PX_PER_MM = 96 / 25.4;

describe('usableAreaPx', () => {
    it('A4 horizontal com margem de 10 mm: 277 × 190 mm em píxeis CSS', () => {
        const area = usableAreaPx('A4', 'landscape');

        expect(area.width).toBeCloseTo(277 * PX_PER_MM, 5);
        expect(area.height).toBeCloseTo(190 * PX_PER_MM, 5);
    });

    it('A4 vertical troca largura e altura; A3 é maior', () => {
        const portrait = usableAreaPx('A4', 'portrait');
        expect(portrait.width).toBeCloseTo(190 * PX_PER_MM, 5);
        expect(portrait.height).toBeCloseTo(277 * PX_PER_MM, 5);
        expect(usableAreaPx('A3', 'landscape').width).toBeGreaterThan(usableAreaPx('A4', 'landscape').width);
    });
});

describe('fitToPage', () => {
    const usable = { width: 1000, height: 700 };

    it('não toca numa tabela que já cabe', () => {
        expect(fitToPage({ tableWidth: 800, tableHeight: 400, headerHeight: 100, usable })).toEqual({
            scale: 1,
            fitsOnePage: true,
            pages: 1,
        });
    });

    it('encolhe pela largura quando só a largura excede', () => {
        const result = fitToPage({ tableWidth: 1100, tableHeight: 300, headerHeight: 100, usable });

        expect(result.scale).toBe(0.909);
        expect(1100 * result.scale).toBeLessThanOrEqual(1000);
        expect(result.fitsOnePage).toBe(true);
    });

    it('encolhe pela altura, descontando o cabeçalho do documento', () => {
        // 700 − 100 = 600 de altura útil para a tabela; 660 → 600/660 = 0,909
        const result = fitToPage({ tableWidth: 500, tableHeight: 660, headerHeight: 100, usable });

        expect(result.scale).toBe(0.909);
        expect(result.fitsOnePage).toBe(true);
    });

    it('desconta também o rodapé, que fica abaixo da tabela e não se encolhe', () => {
        // 700 − 100 − 100 = 500 de altura útil; 660 → 500/660 = 0,757 < piso → não cabe
        const withFooter = fitToPage({ tableWidth: 500, tableHeight: 660, headerHeight: 100, footerHeight: 100, usable });
        expect(withFooter.fitsOnePage).toBe(false);

        // 700 − 100 − 50 = 550; 660 → 0,833: cabe
        const smallFooter = fitToPage({ tableWidth: 500, tableHeight: 660, headerHeight: 100, footerHeight: 50, usable });
        expect(smallFooter).toEqual({ scale: 0.833, fitsOnePage: true, pages: 1 });
    });

    it('reserva a folga pedida na altura', () => {
        // 700 − 100 − 50 = 550; 550 → cabe a 100 % sem folga, mas 550/600 com 50 de folga
        expect(fitToPage({ tableWidth: 500, tableHeight: 550, headerHeight: 100, footerHeight: 50, usable }).scale).toBe(1);
        expect(fitToPage({ tableWidth: 500, tableHeight: 550, headerHeight: 100, footerHeight: 50, slack: 50, usable }).scale).toBe(0.909);
        expect(SAFETY_PX).toBeCloseTo((5 * 96) / 25.4, 5);
    });

    it('usa a menor das duas escalas e arredonda por baixo ao milésimo', () => {
        const result = fitToPage({ tableWidth: 1234.5, tableHeight: 650, headerHeight: 100, usable });

        expect(1234.5 * result.scale).toBeLessThanOrEqual(1000);
        expect(result.scale).toBe(Math.floor((1000 / 1234.5) * 1000) / 1000);
    });

    it('nunca desce abaixo do piso: fixa-se nele e conta páginas', () => {
        const result = fitToPage({ tableWidth: 1000, tableHeight: 3000, headerHeight: 100, usable });

        expect(result.scale).toBe(FLOOR_SCALE);
        expect(result.fitsOnePage).toBe(false);
        // 3000 × 0,8 = 2400 de altura escalada, 600 úteis por página → 4
        expect(result.pages).toBe(4);
    });

    it('o piso é 6 pt sobre 7,5 pt', () => {
        expect(BASE_FONT_PT).toBe(7.5);
        expect(FLOOR_FONT_PT).toBe(6);
        expect(FLOOR_SCALE).toBe(0.8);
    });

    it('medições inválidas devolvem escala 1 e uma página', () => {
        for (const bad of [0, -5, Number.NaN]) {
            expect(fitToPage({ tableWidth: bad, tableHeight: 400, headerHeight: 100, usable })).toEqual({
                scale: 1,
                fitsOnePage: true,
                pages: 1,
            });
            expect(fitToPage({ tableWidth: 400, tableHeight: bad, headerHeight: 100, usable })).toEqual({
                scale: 1,
                fitsOnePage: true,
                pages: 1,
            });
        }

        expect(fitToPage({ tableWidth: 400, tableHeight: 400, headerHeight: Number.NaN, usable }).scale).toBe(1);
        expect(fitToPage({ tableWidth: 400, tableHeight: 400, headerHeight: 100, usable: { width: 0, height: 700 } }).scale).toBe(1);
    });
});

describe('fitNotice', () => {
    it('é nulo quando cabe a 100 %', () => {
        expect(fitNotice({ scale: 1, fitsOnePage: true, pages: 1 })).toBeNull();
    });

    it('diz a percentagem quando cabe encolhida', () => {
        expect(fitNotice({ scale: 0.857, fitsOnePage: true, pages: 1 })).toBe('Imprime-se a 86 % para caber numa página.');
    });

    it('diz quantas páginas saem quando nem a 6 pt cabe', () => {
        expect(fitNotice({ scale: 0.8, fitsOnePage: false, pages: 3 })).toBe('A 6 pt ainda não cabe numa página: sai em 3 páginas.');
        expect(fitNotice({ scale: 0.8, fitsOnePage: false, pages: 2 })).toBe('A 6 pt ainda não cabe numa página: sai em 2 páginas.');
    });
});
