import { describe, expect, it } from 'vitest';

/**
 * Teste-guarda do CSS de impressão: apanha, no texto dos `.vue`, as três causas
 * dos defeitos de impressão da 0.173.0.
 *
 * O LAYOUT REAL SÓ SE PROVA NO BROWSER (`emulate_media('print')` + `page.pdf`);
 * o jsdom não pagina. O que isto garante é que ninguém reintroduz, num
 * `<style>` sem âmbito (que fica carregado na SPA depois de a página sair e
 * afeta as impressões seguintes), um dos três padrões que causaram os defeitos:
 *   1. `body *` sem `:has(.document-print)` — apagava a folha das páginas
 *      seguintes («relatório → mapa em branco»);
 *   2. `position: absolute` / `inset: 0` numa regra de impressão — prendia a
 *      folha à altura do <main> e cortava o que passava da primeira página;
 *   3. um `@page` estático — o papel da página anterior ficava para sempre.
 */
const sources = import.meta.glob(
    [
        '../pages/reports/Show.vue',
        '../pages/evaluation-sheets/Show.vue',
        '../pages/reports/class-documents/MeasuresMap.vue',
        '../pages/results/Summary.vue',
        '../components/documents/PrintableDocument.vue',
    ],
    { query: '?raw', import: 'default', eager: true },
) as Record<string, string>;

function styleBlocks(source: string): string[] {
    return Array.from(source.matchAll(/^<style[^>]*>([\s\S]*?)^<\/style>/gm), (match) => match[1]);
}

function printCss(source: string): string {
    return styleBlocks(source)
        .join('\n')
        // Sem comentários: os que explicam o defeito citam os padrões proibidos.
        .replace(/\/\*[\s\S]*?\*\//g, '');
}

describe('CSS de impressão dos documentos', () => {
    it('lê os cinco ficheiros', () => {
        expect(Object.keys(sources)).toHaveLength(5);
    });

    for (const [path, source] of Object.entries(sources)) {
        const css = printCss(source);

        it(`${path}: sem «body *» solto`, () => {
            const selectors = Array.from(css.matchAll(/([^{}]+)\{/g), (match) => match[1]);

            for (const selector of selectors.flatMap((entry) => entry.split(','))) {
                if (/body\s*\*/.test(selector) && !selector.includes(':has(.document-print)')) {
                    throw new Error(`Seletor «${selector.trim()}» apagaria a impressão de outras páginas.`);
                }
            }
        });

        it(`${path}: sem position absolute nem inset 0`, () => {
            expect(css).not.toMatch(/position:\s*absolute/);
            expect(css).not.toMatch(/inset:\s*0/);
        });

        it(`${path}: sem @page estático`, () => {
            expect(css).not.toMatch(/@page/);
        });
    }
});
