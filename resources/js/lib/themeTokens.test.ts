/// <reference types="node" />
// ^ o tsconfig usa `types: ["vite/client"]` como whitelist; esta referência
//   acrescenta o Node só a este ficheiro. E lê-se por `node:fs`, não por
//   `?raw`: o plugin Tailwind do Vite intercepta o app.css mesmo com `?raw`
//   e entrega o CSS COMPILADO — sem `:root` nenhum lá dentro.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

// Relativo à raiz do repo (o vitest corre de lá); `import.meta.url` no
// ambiente jsdom não é um URL file:// e não serve de âncora.
const css = readFileSync(resolve(process.cwd(), 'resources/css/app.css'), 'utf-8');

/**
 * OS DOIS TEMAS SÃO A MESMA IDENTIDADE — este ficheiro é o que o afirma.
 *
 * O defeito que motivou estas guardas (SUP-UEVAH4, 2026-09-04): o tema claro
 * tinha a marca (navy, âmbar) e o escuro era zinc genérico do starter kit — a
 * marca desaparecia exactamente no tema em que o produto era usado. Nada
 * falhava: um token esquecido no `.dark` cai em cascata para o valor claro ou
 * para o browser, e ninguém dá por isso até olhar.
 *
 * Duas afirmações, ambas sobre o CSS como texto (a fonte, não o computado):
 *
 *   1. PARIDADE — o bloco `.dark` define exactamente as mesmas chaves que o
 *      `:root` (menos `--radius`, que é geometria e não cor). Um token novo no
 *      claro sem gémeo escuro parte aqui, no commit, e não três semanas depois
 *      num ecrã escuro estranho.
 *
 *   2. CONTRASTE — os pares texto/fundo de que o chrome vive cumprem
 *      WCAG 2.2 AA (≥ 4.5:1), calculado dos próprios valores HSL, nos DOIS
 *      temas. O `.impeccable.md` promete AA; uma promessa sem cálculo é um
 *      comentário.
 */

function block(selector: string): Record<string, string> {
    // O ficheiro tem mais do que um `:root` (easings de animação, media
    // queries). O bloco de TOKENS é o que define `--background` — procura-se
    // por conteúdo, não pela primeira ocorrência.
    let start = -1;

    for (let at = css.indexOf(`${selector} {`); at !== -1; at = css.indexOf(`${selector} {`, at + 1)) {
        if (css.slice(at, css.indexOf('}', at)).includes('--background:')) {
            start = at;
            break;
        }
    }

    expect(start, `bloco de tokens ${selector} não encontrado`).toBeGreaterThan(-1);

    const body = css.slice(start, css.indexOf('}', start));
    const tokens: Record<string, string> = {};

    for (const match of body.matchAll(/(--[\w-]+):\s*([^;]+);/g)) {
        tokens[match[1]] = match[2].trim();
    }

    return tokens;
}

/** `hsl(h s% l%)` (vírgulas toleradas) → [r, g, b] em 0..1. */
function rgbFrom(hslText: string): [number, number, number] {
    const match = hslText.match(/hsl\(\s*([\d.]+)[,\s]+([\d.]+)%[,\s]+([\d.]+)%\s*\)/);
    expect(match, `não é um hsl(): ${hslText}`).not.toBeNull();

    const hue = Number(match![1]) / 360;
    const saturation = Number(match![2]) / 100;
    const lightness = Number(match![3]) / 100;

    if (saturation === 0) {
        return [lightness, lightness, lightness];
    }

    const q = lightness < 0.5 ? lightness * (1 + saturation) : lightness + saturation - lightness * saturation;
    const p = 2 * lightness - q;

    const channel = (t: number): number => {
        let value = t;

        if (value < 0) {
value += 1;
}

        if (value > 1) {
value -= 1;
}

        if (value < 1 / 6) {
return p + (q - p) * 6 * value;
}

        if (value < 1 / 2) {
return q;
}

        if (value < 2 / 3) {
return p + (q - p) * (2 / 3 - value) * 6;
}

        return p;
    };

    return [channel(hue + 1 / 3), channel(hue), channel(hue - 1 / 3)];
}

function contrast(backgroundHsl: string, foregroundHsl: string): number {
    const luminance = (rgb: [number, number, number]): number => {
        const [r, g, b] = rgb.map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));

        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };

    const lighter = Math.max(luminance(rgbFrom(backgroundHsl)), luminance(rgbFrom(foregroundHsl)));
    const darker = Math.min(luminance(rgbFrom(backgroundHsl)), luminance(rgbFrom(foregroundHsl)));

    return (lighter + 0.05) / (darker + 0.05);
}

const light = block(':root');
const dark = block('.dark');

/** Os pares de que o chrome vive. [fundo, texto] */
const PAIRS: [string, string][] = [
    ['--background', '--foreground'],
    ['--card', '--card-foreground'],
    ['--muted', '--muted-foreground'],
    ['--primary', '--primary-foreground'],
    ['--accent', '--accent-foreground'],
    ['--sidebar-background', '--sidebar-foreground'],
    ['--sidebar-background', '--sidebar-primary'],
    ['--sidebar-accent', '--sidebar-accent-foreground'],
];

describe('paridade dos temas', () => {
    it('o .dark define as mesmas chaves que o :root — um token sem gémeo parte aqui', () => {
        const geometry = new Set(['--radius']);
        const lightKeys = Object.keys(light).filter((key) => !geometry.has(key)).sort();
        const darkKeys = Object.keys(dark).filter((key) => !geometry.has(key)).sort();

        expect(darkKeys).toEqual(lightKeys);
    });
});

describe.each([
    ['claro', light],
    ['escuro', dark],
])('contraste AA no tema %s', (_theme, tokens) => {
    it.each(PAIRS)('%s sobre %s ≥ 4.5:1', (backgroundKey, foregroundKey) => {
        const ratio = contrast(tokens[backgroundKey], tokens[foregroundKey]);

        expect(ratio, `${foregroundKey} sobre ${backgroundKey} = ${ratio.toFixed(2)}:1`).toBeGreaterThanOrEqual(4.5);
    });
});
