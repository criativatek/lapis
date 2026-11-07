# Impressão numa página — PR 1 (grelha de correção) — Plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A grelha de correção impressa pelo browser cabe numa A4 horizontal: um só bloco de colunas, letra a encolher até 6 pt, cabeçalhos em sigla com legenda, e um utilitário de ajuste à página reutilizável pelas PRs seguintes (pauta, intercalares).

**Architecture:** O servidor (`InstrumentGridLayout`/`InstrumentGrid`) ganha a disposição `one_page` (um bloco, siglas e legenda de siglas no JSON do documento); o cliente mede a tabela e aplica uma escala por `zoom` através de uma prop nova do `PrintableDocument`, decidida por funções puras em `printFit.ts`. O PDF do servidor força sempre a disposição em blocos e não muda.

**Tech Stack:** Laravel 12 (PHP 8.4, PHPUnit 12, Pint, PHPStan), Inertia + Vue 3 + TypeScript, Tailwind 4, vitest + @vue/test-utils (jsdom). Spec: `docs/superpowers/specs/2026-11-07-impressao-uma-pagina-design.md`.

## Global Constraints

- Worktree `D:/worktrees/lapis-draft-recovery`, branch `feat/print-one-page` (partida de main `d3ccf11f`, 0.190.2). Nunca tocar em `D:/HERD/lapis`. Caminho sempre em minúsculas.
- PHP e Composer fora do PATH: `export PATH="/c/Users/PCUser/.config/herd/bin/php84:$PATH"` antes de qualquer `php`/`vendor/bin`. Testes PHP: `php artisan test --filter=<Nome>`. Pint: `vendor/bin/pint --test <ficheiro>`. Vitest: `npx vitest run <ficheiro>`. ESLint: `npx eslint <ficheiro>`.
- Texto novo diz sempre «Lapispro», em português de Portugal. Nomes de classes e ficheiros em inglês, como o resto do código.
- Papel: A4 horizontal, margem compacta 10 mm (a do `PrintableDocument`). Letra base da tabela compacta 7,5 pt; piso 6 pt (escala mínima 0,8).
- Cabeçalho das perguntas no modo «Uma página»: só o número («Q12» → «12», «Item 3» → «3», «2.1» → «2.1», «A» → «A»).
- O PDF do dompdf não muda: `InstrumentGridDocumentController::pdf` força `layout = blocks`.
- Versão alvo 0.191.0: `config/app.php` + entrada no topo de `CHANGELOG.md` com data `2026-11-07` (ou a data do commit final). `ReleaseVersionTest` afirma ambos.
- Commits com `git add <caminhos explícitos>` (nunca `-A`), mensagem em português, rodapé `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Não correr suites PHP em paralelo com outra sessão (Storage::fake partilhado).

---

## File Structure

| Ficheiro | Responsabilidade |
| --- | --- |
| `resources/js/lib/printFit.ts` (novo) | Funções puras: área útil, escala por largura e altura, piso, estimativa de páginas, texto do aviso. |
| `resources/js/lib/printFit.test.ts` (novo) | Casos numéricos do utilitário. |
| `resources/js/lib/abbreviations.ts` (novo) | Siglas no cliente (mesma regra que o PHP); usado nas PRs 2 e 3, aqui só com testes. |
| `resources/js/lib/abbreviations.test.ts` (novo) | Casos da secção 5 da spec. |
| `app/Support/Documents/Abbreviations.php` (novo) | Siglas no servidor: número da pergunta, grupo, domínio, colunas-resumo, menções. |
| `tests/Unit/Documents/AbbreviationsTest.php` (novo) | Os mesmos casos do lado TS. |
| `resources/js/components/documents/PrintableDocument.vue` | Prop `fit` → variável CSS `--document-print-zoom`; regra `zoom` em `.document-print-fit`. |
| `resources/js/components/documents/PrintableDocument.test.ts` | Teste da prop. |
| `app/Services/Reporting/ClassDocuments/InstrumentGridLayout.php` | Constantes `BLOCKS`/`ONE_PAGE`; `plan()` com `$layout`. |
| `tests/Unit/Reporting/InstrumentGridLayoutTest.php` | `one_page` → um bloco. |
| `app/Services/Reporting/ClassDocuments/InstrumentGridOptions.php` | Opção `layout` (`disposicao`), legenda desligada por defeito em `one_page`, `withLayout()`. |
| `tests/Unit/Reporting/InstrumentGridOptionsTest.php` | Leitura da query e defaults. |
| `app/Services/Reporting/ClassDocuments/InstrumentGrid.php` | `short` nas colunas, grupos e células de nível/domínio; `abbreviations` no documento. |
| `tests/Feature/Reporting/InstrumentGridDocumentTest.php` | Documento traz siglas e legenda; PDF ignora `disposicao`. |
| `app/Http/Controllers/InstrumentGridDocumentController.php` | PDF força `blocks`. |
| `resources/js/pages/instruments/GridDocument.vue` | Seletor «Disposição», tabela compacta, medição e `fit`, aviso, legenda das siglas. |
| `resources/js/pages/instruments/GridDocument.test.ts` | Comportamento novo da página. |
| `config/app.php`, `CHANGELOG.md`, `docs/reports-class-documents.md` | Versão 0.191.0 e nota sobre o modo «Uma página» (browser). |

---

### Task 1: `printFit.ts` — ajuste à página em funções puras

**Files:**
- Create: `resources/js/lib/printFit.ts`
- Test: `resources/js/lib/printFit.test.ts`

**Interfaces:**
- Produces: `usableAreaPx(paper, orientation)`, `fitToPage(input): FitResult`, `fitNotice(result): string | null`, constantes `BASE_FONT_PT`, `FLOOR_FONT_PT`, `FLOOR_SCALE`, `COMPACT_MARGIN_MM`, `PX_PER_MM`; tipos `FitInput`, `FitResult` (usados na Task 9 e nas PRs 2 e 3).

- [ ] **Step 1: Escrever os testes (falham: módulo inexistente)**

```ts
// resources/js/lib/printFit.test.ts
import { describe, expect, it } from 'vitest';
import { BASE_FONT_PT, FLOOR_FONT_PT, FLOOR_SCALE, fitNotice, fitToPage, usableAreaPx } from './printFit';

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
```

- [ ] **Step 2: Correr para ver falhar**

Run: `cd /d/worktrees/lapis-draft-recovery && npx vitest run resources/js/lib/printFit.test.ts`
Expected: FAIL — `Failed to resolve import "./printFit"`.

- [ ] **Step 3: Implementar**

```ts
// resources/js/lib/printFit.ts
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
```

- [ ] **Step 4: Correr os testes**

Run: `npx vitest run resources/js/lib/printFit.test.ts`
Expected: PASS (todos). Nota: no caso «encolhe pela altura», 600/660 = 0,90909… → 0,909; no caso do piso, 2400/600 = 4 páginas.

- [ ] **Step 5: ESLint e commit**

```bash
npx eslint resources/js/lib/printFit.ts resources/js/lib/printFit.test.ts
git add resources/js/lib/printFit.ts resources/js/lib/printFit.test.ts
git commit -m "feat(print): printFit — ajuste de uma tabela a uma página, em funções puras

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: `abbreviations.ts` — siglas no cliente

**Files:**
- Create: `resources/js/lib/abbreviations.ts`
- Test: `resources/js/lib/abbreviations.test.ts`

**Interfaces:**
- Produces: `questionNumber(code)`, `groupShort(label)`, `domainShort(name, taken?)`, `mentionShort(label)`, `uniqueMentionShorts(bands: {code,label}[]): Map<string,string>` (label → sigla ou código), `SUMMARY_SHORT` (mapa fixo). Usados nas PRs 2 e 3; a regra tem de ser idêntica à de `Abbreviations.php` (Task 3).

- [ ] **Step 1: Testes**

```ts
// resources/js/lib/abbreviations.test.ts
import { describe, expect, it } from 'vitest';
import { domainShort, groupShort, mentionShort, questionNumber, SUMMARY_SHORT, uniqueMentionShorts } from './abbreviations';

describe('questionNumber', () => {
    it('tira o prefixo e deixa o número', () => {
        expect(questionNumber('Q12')).toBe('12');
        expect(questionNumber('q3')).toBe('3');
        expect(questionNumber('Item 3')).toBe('3');
        expect(questionNumber('Pergunta 7')).toBe('7');
        expect(questionNumber('P2.1')).toBe('2.1');
        expect(questionNumber('Q 4a')).toBe('4a');
    });

    it('deixa intacto o que não é um prefixo seguido de dígito', () => {
        expect(questionNumber('2.1')).toBe('2.1');
        expect(questionNumber('A')).toBe('A');
        expect(questionNumber('Prova')).toBe('Prova');
        expect(questionNumber('')).toBe('');
    });
});

describe('groupShort', () => {
    it('usa o numeral do rótulo', () => {
        expect(groupShort('GRUPO V - ESCRITA')).toBe('G.V');
        expect(groupShort('Grupo 2')).toBe('G.2');
        expect(groupShort('grupo iii — leitura')).toBe('G.III');
    });

    it('sem numeral, iniciais até 4 letras, sem partículas', () => {
        expect(groupShort('Expressão escrita')).toBe('EE');
        expect(groupShort('Compreensão do oral e da escrita')).toBe('COE');
        expect(groupShort('Um Dois Três Quatro Cinco')).toBe('UDTQ');
    });

    it('vazio ou nulo fica vazio', () => {
        expect(groupShort('')).toBe('');
        expect(groupShort(null)).toBe('');
    });
});

describe('domainShort', () => {
    it('uma palavra: quatro letras e ponto, ou a palavra inteira se for curta', () => {
        expect(domainShort('Oralidade')).toBe('Oral.');
        expect(domainShort('Leitura')).toBe('Leit.');
        expect(domainShort('Escrita')).toBe('Escr.');
        expect(domainShort('Gramática')).toBe('Gram.');
        expect(domainShort('Arte')).toBe('Arte');
    });

    it('várias palavras: três letras e ponto por palavra, sem partículas', () => {
        expect(domainShort('Educação Literária')).toBe('Edu. Lit.');
        expect(domainShort('Compreensão do Oral')).toBe('Com. Ora.');
    });

    it('evita colidir com siglas já atribuídas acrescentando letras', () => {
        expect(domainShort('Geometria', ['Geom.'])).toBe('Geome.');
        expect(domainShort('Geometria', ['Geom.', 'Geome.'])).toBe('Geomet.');
    });
});

describe('mentionShort', () => {
    it('iniciais das palavras com maiúscula', () => {
        expect(mentionShort('Muito Bom')).toBe('MB');
        expect(mentionShort('Insuficiente')).toBe('I');
        expect(mentionShort('Suficiente')).toBe('S');
        expect(mentionShort('Bom')).toBe('B');
        expect(mentionShort('Não Satisfaz')).toBe('NS');
        expect(mentionShort('Satisfaz bem')).toBe('SB');
    });
});

describe('uniqueMentionShorts', () => {
    it('siglas quando não colidem', () => {
        const map = uniqueMentionShorts([
            { code: '1', label: 'Insuficiente' },
            { code: '3', label: 'Suficiente' },
            { code: '4', label: 'Bom' },
            { code: '5', label: 'Muito Bom' },
        ]);

        expect(map.get('Muito Bom')).toBe('MB');
        expect(map.get('Insuficiente')).toBe('I');
    });

    it('códigos para TODAS quando duas colidem', () => {
        const map = uniqueMentionShorts([
            { code: '2', label: 'Satisfaz' },
            { code: '3', label: 'Satisfaz Bem' },
            { code: '4', label: 'Satisfaz Bastante' },
        ]);

        expect(map.get('Satisfaz')).toBe('2');
        expect(map.get('Satisfaz Bem')).toBe('3');
        expect(map.get('Satisfaz Bastante')).toBe('4');
    });
});

describe('SUMMARY_SHORT', () => {
    it('as colunas-resumo fixas', () => {
        expect(SUMMARY_SHORT).toEqual({
            raw: 'Bruta',
            result: 'Res.',
            level: 'Nív.',
            weighted_average: 'Média',
            assigned_level: 'Nível',
        });
    });
});
```

- [ ] **Step 2: Correr para ver falhar**

Run: `npx vitest run resources/js/lib/abbreviations.test.ts`
Expected: FAIL — módulo inexistente.

- [ ] **Step 3: Implementar**

```ts
// resources/js/lib/abbreviations.ts
/**
 * Siglas para o papel: uma regra só, igual à de `App\Support\Documents\Abbreviations`
 * (PHP). Os casos de teste são os mesmos dos dois lados; quem mudar um, muda o outro.
 */

/** Palavras que não contam para uma sigla. */
const PARTICLES = new Set(['de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o', 'as', 'os', 'em', 'no', 'na', 'nos', 'nas', 'com', 'para', 'por']);

function words(text: string): string[] {
    return text
        .trim()
        .split(/[\s\-–—/]+/)
        .map((word) => word.replace(/[^\p{L}\p{N}]/gu, ''))
        .filter((word) => word !== '' && !PARTICLES.has(word.toLocaleLowerCase('pt-PT')));
}

function capitalize(word: string): string {
    return word.charAt(0).toLocaleUpperCase('pt-PT') + word.slice(1);
}

/** «Q12» → «12», «Item 3» → «3», «2.1» → «2.1», «A» → «A». */
export function questionNumber(code: string): string {
    const match = /^(?:q|item|pergunta|p)\s*[.\-]?\s*(\d.*)$/iu.exec(code.trim());

    return match ? match[1].trim() : code.trim();
}

/** «GRUPO V - ESCRITA» → «G.V»; «Expressão escrita» → «EE»; vazio → «». */
export function groupShort(label: string | null | undefined): string {
    if (!label || label.trim() === '') {
        return '';
    }

    const numeral = /\b([IVXLC]+|\d+)\b/iu.exec(label);

    if (numeral) {
        return `G.${numeral[1].toLocaleUpperCase('pt-PT')}`;
    }

    return words(label)
        .slice(0, 4)
        .map((word) => word.charAt(0).toLocaleUpperCase('pt-PT'))
        .join('');
}

/**
 * «Oralidade» → «Oral.», «Educação Literária» → «Edu. Lit.». Com `taken`, acrescenta
 * letras à primeira palavra até não colidir.
 */
export function domainShort(name: string, taken: string[] = []): string {
    const parts = words(name);

    if (parts.length === 0) {
        return '';
    }

    const build = (firstLength: number): string => {
        if (parts.length === 1) {
            const word = parts[0];

            return word.length <= firstLength ? capitalize(word) : `${capitalize(word.slice(0, firstLength))}.`;
        }

        return parts
            .map((word, index) => {
                const length = index === 0 ? firstLength : 3;

                return word.length <= length ? capitalize(word) : `${capitalize(word.slice(0, length))}.`;
            })
            .join(' ');
    };

    let length = parts.length === 1 ? 4 : 3;
    let candidate = build(length);

    while (taken.includes(candidate) && length < parts[0].length) {
        length++;
        candidate = build(length);
    }

    return candidate;
}

/** «Muito Bom» → «MB», «Insuficiente» → «I». */
export function mentionShort(label: string): string {
    return words(label)
        .map((word) => word.charAt(0).toLocaleUpperCase('pt-PT'))
        .join('');
}

/**
 * A sigla de cada menção da escala — ou, se duas colidirem, o CÓDIGO de todas,
 * para nunca haver duas menções com a mesma letra no papel.
 */
export function uniqueMentionShorts(bands: { code: string; label: string }[]): Map<string, string> {
    const shorts = bands.map((band) => mentionShort(band.label));
    const collides = new Set(shorts).size !== shorts.length;

    return new Map(bands.map((band, index) => [band.label, collides ? band.code : shorts[index]]));
}

/** Colunas-resumo com sigla fixa, pela sua chave. */
export const SUMMARY_SHORT: Record<string, string> = {
    raw: 'Bruta',
    result: 'Res.',
    level: 'Nív.',
    weighted_average: 'Média',
    assigned_level: 'Nível',
};
```

- [ ] **Step 4: Correr os testes**

Run: `npx vitest run resources/js/lib/abbreviations.test.ts`
Expected: PASS. Se «Compreensão do oral e da escrita» → «COE» falhar, confirmar que `PARTICLES` apanha «do», «e», «da» (sim) e que a maiúscula vem do `toLocaleUpperCase`.

- [ ] **Step 5: ESLint e commit**

```bash
npx eslint resources/js/lib/abbreviations.ts resources/js/lib/abbreviations.test.ts
git add resources/js/lib/abbreviations.ts resources/js/lib/abbreviations.test.ts
git commit -m "feat(print): siglas para o papel (cliente)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: `Abbreviations.php` — a mesma regra no servidor

**Files:**
- Create: `app/Support/Documents/Abbreviations.php`
- Test: `tests/Unit/Documents/AbbreviationsTest.php`

**Interfaces:**
- Produces: `Abbreviations::questionNumber(string): string`, `::groupShort(?string): string`, `::domainShort(string, list<string> $taken = []): string`, `::mentionShort(string): string`, `::uniqueMentionShorts(list<array{code: string, label: string}>): array<string, string>` (label ⇒ sigla/código), `::summaryShort(string $kind): ?string`. Usados na Task 7.

- [ ] **Step 1: Testes**

```php
<?php
// tests/Unit/Documents/AbbreviationsTest.php

namespace Tests\Unit\Documents;

use App\Support\Documents\Abbreviations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A mesma regra de `resources/js/lib/abbreviations.ts`: os casos são os mesmos
 * dos dois lados, e quem mudar um muda o outro.
 */
class AbbreviationsTest extends TestCase
{
    /** @return list<array{string, string}> */
    public static function questionCodes(): array
    {
        return [
            ['Q12', '12'], ['q3', '3'], ['Item 3', '3'], ['Pergunta 7', '7'], ['P2.1', '2.1'], ['Q 4a', '4a'],
            ['2.1', '2.1'], ['A', 'A'], ['Prova', 'Prova'], ['', ''],
        ];
    }

    #[Test]
    #[DataProvider('questionCodes')]
    public function a_question_keeps_only_its_number(string $code, string $expected): void
    {
        $this->assertSame($expected, Abbreviations::questionNumber($code));
    }

    #[Test]
    public function a_group_uses_its_numeral_or_its_initials(): void
    {
        $this->assertSame('G.V', Abbreviations::groupShort('GRUPO V - ESCRITA'));
        $this->assertSame('G.2', Abbreviations::groupShort('Grupo 2'));
        $this->assertSame('G.III', Abbreviations::groupShort('grupo iii — leitura'));
        $this->assertSame('EE', Abbreviations::groupShort('Expressão escrita'));
        $this->assertSame('COE', Abbreviations::groupShort('Compreensão do oral e da escrita'));
        $this->assertSame('UDTQ', Abbreviations::groupShort('Um Dois Três Quatro Cinco'));
        $this->assertSame('', Abbreviations::groupShort(''));
        $this->assertSame('', Abbreviations::groupShort(null));
    }

    #[Test]
    public function a_domain_is_shortened_and_never_collides(): void
    {
        $this->assertSame('Oral.', Abbreviations::domainShort('Oralidade'));
        $this->assertSame('Leit.', Abbreviations::domainShort('Leitura'));
        $this->assertSame('Escr.', Abbreviations::domainShort('Escrita'));
        $this->assertSame('Gram.', Abbreviations::domainShort('Gramática'));
        $this->assertSame('Arte', Abbreviations::domainShort('Arte'));
        $this->assertSame('Edu. Lit.', Abbreviations::domainShort('Educação Literária'));
        $this->assertSame('Com. Ora.', Abbreviations::domainShort('Compreensão do Oral'));
        $this->assertSame('Geome.', Abbreviations::domainShort('Geometria', ['Geom.']));
        $this->assertSame('Geomet.', Abbreviations::domainShort('Geometria', ['Geom.', 'Geome.']));
    }

    #[Test]
    public function a_mention_is_its_initials_and_codes_replace_colliding_ones(): void
    {
        $this->assertSame('MB', Abbreviations::mentionShort('Muito Bom'));
        $this->assertSame('I', Abbreviations::mentionShort('Insuficiente'));
        $this->assertSame('NS', Abbreviations::mentionShort('Não Satisfaz'));

        $unique = Abbreviations::uniqueMentionShorts([
            ['code' => '1', 'label' => 'Insuficiente'], ['code' => '3', 'label' => 'Suficiente'],
            ['code' => '4', 'label' => 'Bom'], ['code' => '5', 'label' => 'Muito Bom'],
        ]);
        $this->assertSame(['Insuficiente' => 'I', 'Suficiente' => 'S', 'Bom' => 'B', 'Muito Bom' => 'MB'], $unique);

        $colliding = Abbreviations::uniqueMentionShorts([
            ['code' => '2', 'label' => 'Satisfaz'], ['code' => '3', 'label' => 'Satisfaz Bem'], ['code' => '4', 'label' => 'Satisfaz Bastante'],
        ]);
        $this->assertSame(['Satisfaz' => '2', 'Satisfaz Bem' => '3', 'Satisfaz Bastante' => '4'], $colliding);
    }

    #[Test]
    public function the_summary_columns_have_fixed_shorts(): void
    {
        $this->assertSame('Bruta', Abbreviations::summaryShort('raw'));
        $this->assertSame('Res.', Abbreviations::summaryShort('result'));
        $this->assertSame('Nív.', Abbreviations::summaryShort('level'));
        $this->assertSame('Média', Abbreviations::summaryShort('weighted_average'));
        $this->assertSame('Nível', Abbreviations::summaryShort('assigned_level'));
        $this->assertNull(Abbreviations::summaryShort('domain'));
    }
}
```

- [ ] **Step 2: Correr para ver falhar**

Run: `export PATH="/c/Users/PCUser/.config/herd/bin/php84:$PATH" && php artisan test --filter=AbbreviationsTest`
Expected: FAIL — `Class "App\Support\Documents\Abbreviations" not found`.

- [ ] **Step 3: Implementar**

```php
<?php
// app/Support/Documents/Abbreviations.php

namespace App\Support\Documents;

/**
 * Siglas para o papel — a mesma regra de `resources/js/lib/abbreviations.ts`.
 *
 * Uma grelha que cabe numa página só cabe porque os cabeçalhos encolhem: o
 * número da pergunta em vez do código, «G.V» em vez de «GRUPO V - ESCRITA»,
 * «Edu. Lit.» em vez de «Educação Literária». A regra vive aqui, uma vez, e a
 * legenda do documento desfaz cada sigla por extenso.
 */
final class Abbreviations
{
    /** Palavras que não contam para uma sigla. */
    private const PARTICLES = ['de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o', 'as', 'os', 'em', 'no', 'na', 'nos', 'nas', 'com', 'para', 'por'];

    private const SUMMARY = [
        'raw' => 'Bruta',
        'result' => 'Res.',
        'level' => 'Nív.',
        'weighted_average' => 'Média',
        'assigned_level' => 'Nível',
    ];

    /** «Q12» → «12», «Item 3» → «3», «2.1» → «2.1», «A» → «A». */
    public static function questionNumber(string $code): string
    {
        $code = trim($code);

        if (preg_match('/^(?:q|item|pergunta|p)\s*[.\-]?\s*(\d.*)$/iu', $code, $match) === 1) {
            return trim($match[1]);
        }

        return $code;
    }

    /** «GRUPO V - ESCRITA» → «G.V»; «Expressão escrita» → «EE»; vazio → «». */
    public static function groupShort(?string $label): string
    {
        if ($label === null || trim($label) === '') {
            return '';
        }

        if (preg_match('/\b([IVXLC]+|\d+)\b/iu', $label, $match) === 1) {
            return 'G.'.mb_strtoupper($match[1]);
        }

        $initials = array_map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), array_slice(self::words($label), 0, 4));

        return implode('', $initials);
    }

    /**
     * «Oralidade» → «Oral.», «Educação Literária» → «Edu. Lit.»; com `$taken`,
     * acrescenta letras à primeira palavra até não colidir.
     *
     * @param  list<string>  $taken
     */
    public static function domainShort(string $name, array $taken = []): string
    {
        $parts = self::words($name);

        if ($parts === []) {
            return '';
        }

        $build = function (int $firstLength) use ($parts): string {
            $piece = function (string $word, int $length): string {
                return mb_strlen($word) <= $length ? self::capitalize($word) : self::capitalize(mb_substr($word, 0, $length)).'.';
            };

            if (count($parts) === 1) {
                return $piece($parts[0], $firstLength);
            }

            $pieces = [];

            foreach ($parts as $index => $word) {
                $pieces[] = $piece($word, $index === 0 ? $firstLength : 3);
            }

            return implode(' ', $pieces);
        };

        $length = count($parts) === 1 ? 4 : 3;
        $candidate = $build($length);

        while (in_array($candidate, $taken, true) && $length < mb_strlen($parts[0])) {
            $length++;
            $candidate = $build($length);
        }

        return $candidate;
    }

    /** «Muito Bom» → «MB», «Insuficiente» → «I». */
    public static function mentionShort(string $label): string
    {
        return implode('', array_map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), self::words($label)));
    }

    /**
     * A sigla de cada menção — ou o CÓDIGO de todas quando duas colidem.
     *
     * @param  list<array{code: string, label: string}>  $bands
     * @return array<string, string> rótulo ⇒ sigla ou código
     */
    public static function uniqueMentionShorts(array $bands): array
    {
        $shorts = array_map(fn (array $band): string => self::mentionShort($band['label']), $bands);
        $collides = count(array_unique($shorts)) !== count($shorts);
        $result = [];

        foreach ($bands as $index => $band) {
            $result[$band['label']] = $collides ? $band['code'] : $shorts[$index];
        }

        return $result;
    }

    /** A sigla fixa de uma coluna-resumo, ou null quando a coluna não tem uma (domínios). */
    public static function summaryShort(string $kind): ?string
    {
        return self::SUMMARY[$kind] ?? null;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $raw = preg_split('/[\s\-–—\/]+/u', trim($text)) ?: [];
        $words = [];

        foreach ($raw as $word) {
            $clean = (string) preg_replace('/[^\p{L}\p{N}]/u', '', $word);

            if ($clean !== '' && ! in_array(mb_strtolower($clean), self::PARTICLES, true)) {
                $words[] = $clean;
            }
        }

        return $words;
    }

    private static function capitalize(string $word): string
    {
        return mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
    }
}
```

- [ ] **Step 4: Correr os testes, Pint e PHPStan**

```bash
php artisan test --filter=AbbreviationsTest
vendor/bin/pint --test app/Support/Documents/Abbreviations.php tests/Unit/Documents/AbbreviationsTest.php
vendor/bin/phpstan analyse app/Support/Documents/Abbreviations.php --memory-limit=1G
```
Expected: 5 testes PASS; Pint «passed»; PHPStan sem erros.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Documents/Abbreviations.php tests/Unit/Documents/AbbreviationsTest.php
git commit -m "feat(print): siglas para o papel (servidor), a mesma regra do cliente

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: `PrintableDocument` — prop `fit`

**Files:**
- Modify: `resources/js/components/documents/PrintableDocument.vue:40-62` (props) e `:120-125` (template), CSS `@media print` no fim
- Test: `resources/js/components/documents/PrintableDocument.test.ts`

**Interfaces:**
- Produces: prop `fit?: number | null` (default `null`). Com valor, o elemento raiz leva `style="--document-print-zoom: <fit>"`. No papel, qualquer descendente com a classe `document-print-fit` recebe `zoom: var(--document-print-zoom, 1)`. Usado na Task 9 e nas PRs 2 e 3.

- [ ] **Step 1: Teste**

Acrescentar a `PrintableDocument.test.ts`, dentro do `describe('PrintableDocument')`:

```ts
    it('publica a escala de ajuste numa variável CSS quando a página a passa, e nada quando não passa', () => {
        const bare = mountDocument();
        expect(bare.attributes('style')).toBeUndefined();

        const fitted = mountDocument({ fit: 0.857 });
        expect(fitted.attributes('style')).toContain('--document-print-zoom: 0.857');
    });
```

- [ ] **Step 2: Correr para ver falhar**

Run: `npx vitest run resources/js/components/documents/PrintableDocument.test.ts`
Expected: FAIL no teste novo (`style` indefinido no segundo caso).

- [ ] **Step 3: Implementar**

Em `PrintableDocument.vue`, props (dentro do `defineProps`/`withDefaults`):

```ts
        /** A escala de ajuste à página (Task «printFit»): publicada como `--document-print-zoom`. */
        fit?: number | null;
```
e no `withDefaults`: `fit: null,`.

Template (substituir a abertura do `div` raiz):

```vue
    <div
        class="document-print"
        :data-orientation="orientation"
        :data-compact="compact"
        :style="fit === null ? undefined : { '--document-print-zoom': String(fit) }"
    >
```

CSS, dentro do `@media print { … }` existente, antes do bloco «Preto sobre branco»:

```css
    /* AJUSTE A UMA PÁGINA. A página mede a tabela, decide a escala em
       `printFit.ts` e passa-a na prop `fit`; aqui aplica-se só ao que a página
       marcou com `document-print-fit` (a tabela e a sua legenda), nunca ao
       cabeçalho. `zoom` e não `transform`: o `transform` não altera o fluxo e
       a folha ficaria com o espaço vazio da tabela sem escala. */
    .document-print .document-print-fit {
        zoom: var(--document-print-zoom, 1);
    }
```

- [ ] **Step 4: Correr os testes**

Run: `npx vitest run resources/js/components/documents/PrintableDocument.test.ts resources/js/lib/printStyles.test.ts`
Expected: PASS (o `printStyles.test.ts` continua a aceitar o ficheiro: a regra nova está presa a `.document-print`).

- [ ] **Step 5: Commit**

```bash
npx eslint resources/js/components/documents/PrintableDocument.vue resources/js/components/documents/PrintableDocument.test.ts
git add resources/js/components/documents/PrintableDocument.vue resources/js/components/documents/PrintableDocument.test.ts
git commit -m "feat(print): PrintableDocument aceita a escala de ajuste à página

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: `InstrumentGridLayout` — disposição `one_page`

**Files:**
- Modify: `app/Services/Reporting/ClassDocuments/InstrumentGridLayout.php:40-90`
- Test: `tests/Unit/Reporting/InstrumentGridLayoutTest.php`

**Interfaces:**
- Produces: `InstrumentGridLayout::BLOCKS = 'blocks'`, `::ONE_PAGE = 'one_page'`; `plan(array $itemGroups, array $summaryWidths, string $orientation = AUTO, float $itemWidth = ITEM_WIDTH, string $layout = BLOCKS)`. Com `ONE_PAGE`: `['orientation' => 'landscape', 'blocks' => [['items' => [0..n-1], 'summary' => [0..m-1]]]]`.

- [ ] **Step 1: Testes** (acrescentar à classe `InstrumentGridLayoutTest`)

```php
    #[Test]
    public function one_page_is_a_single_landscape_block_with_every_column_whatever_the_width(): void
    {
        $plan = InstrumentGridLayout::plan($this->ungrouped(40), self::SUMMARY, InstrumentGridLayout::PORTRAIT, InstrumentGridLayout::ITEM_WIDTH, InstrumentGridLayout::ONE_PAGE);

        $this->assertSame(InstrumentGridLayout::LANDSCAPE, $plan['orientation']);
        $this->assertCount(1, $plan['blocks']);
        $this->assertSame(range(0, 39), $plan['blocks'][0]['items']);
        $this->assertSame([0, 1, 2], $plan['blocks'][0]['summary']);
    }

    #[Test]
    public function one_page_with_no_questions_still_yields_one_block_with_the_summary(): void
    {
        $plan = InstrumentGridLayout::plan([], self::SUMMARY, InstrumentGridLayout::AUTO, InstrumentGridLayout::ITEM_WIDTH, InstrumentGridLayout::ONE_PAGE);

        $this->assertSame([['items' => [], 'summary' => [0, 1, 2]]], $plan['blocks']);
    }

    #[Test]
    public function the_default_layout_is_still_blocks(): void
    {
        $plan = InstrumentGridLayout::plan($this->ungrouped(40), self::SUMMARY);

        $this->assertGreaterThan(1, count($plan['blocks']));
    }
```

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=InstrumentGridLayoutTest`
Expected: FAIL — `Undefined constant InstrumentGridLayout::ONE_PAGE`.

- [ ] **Step 3: Implementar**

Em `InstrumentGridLayout.php`, depois de `public const AUTO = 'auto';`:

```php
    /** Blocos de colunas que cabem na página — o que o PDF precisa. */
    public const BLOCKS = 'blocks';

    /**
     * Um só bloco com todas as colunas, em paisagem: a pré-visualização do
     * browser encolhe-o depois até caber numa página (`printFit.ts`). O dompdf
     * nunca recebe esta disposição — não sabe escalar e perderia colunas.
     */
    public const ONE_PAGE = 'one_page';
```

Assinatura e corpo de `plan()`:

```php
    /**
     * @param  list<int|null>  $itemGroups
     * @param  list<float>  $summaryWidths
     * @param  'auto'|'portrait'|'landscape'  $orientation
     * @param  float  $itemWidth
     * @param  'blocks'|'one_page'  $layout  `one_page` ignora larguras: um bloco, paisagem
     * @return array{orientation: 'portrait'|'landscape', blocks: list<array{items: list<int>, summary: list<int>}>}
     */
    public static function plan(array $itemGroups, array $summaryWidths, string $orientation = self::AUTO, float $itemWidth = self::ITEM_WIDTH, string $layout = self::BLOCKS): array
    {
        if ($layout === self::ONE_PAGE) {
            return [
                'orientation' => self::LANDSCAPE,
                'blocks' => [[
                    'items' => $itemGroups === [] ? [] : range(0, count($itemGroups) - 1),
                    'summary' => array_keys($summaryWidths),
                ]],
            ];
        }

        // … corpo existente inalterado …
```

- [ ] **Step 4: Correr os testes, Pint, PHPStan**

```bash
php artisan test --filter=InstrumentGridLayoutTest
vendor/bin/pint --test app/Services/Reporting/ClassDocuments/InstrumentGridLayout.php tests/Unit/Reporting/InstrumentGridLayoutTest.php
vendor/bin/phpstan analyse app/Services/Reporting/ClassDocuments/InstrumentGridLayout.php --memory-limit=1G
```
Expected: todos PASS (os antigos e os 3 novos).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reporting/ClassDocuments/InstrumentGridLayout.php tests/Unit/Reporting/InstrumentGridLayoutTest.php
git commit -m "feat(grid-document): disposição «uma página» no planeador de colunas

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: `InstrumentGridOptions` — opção `disposicao`

**Files:**
- Modify: `app/Services/Reporting/ClassDocuments/InstrumentGridOptions.php`
- Test: `tests/Unit/Reporting/InstrumentGridOptionsTest.php`

**Interfaces:**
- Produces: propriedade `public string $layout = InstrumentGridLayout::ONE_PAGE`; query `disposicao=uma-pagina|blocos`; `toArray()` ganha `'layout' => 'one_page'|'blocks'`; `withLayout(string): self`. **Legenda das questões**: por defeito `false` em `one_page`, `true` em `blocks` (a query `legenda=1|0` continua a mandar).

- [ ] **Step 1: Testes**

Atualizar o teste existente `everything_is_on_and_the_orientation_automatic_by_default` para o novo default:

```php
    #[Test]
    public function one_page_is_the_default_and_turns_the_questions_legend_off(): void
    {
        $options = InstrumentGridOptions::fromQuery([]);

        $this->assertSame(InstrumentGridLayout::ONE_PAGE, $options->layout);
        $this->assertFalse($options->legend);
        $this->assertSame(
            ['raw_score' => true, 'result' => true, 'domains' => true, 'legend' => false, 'charts' => false, 'orientation' => 'auto', 'layout' => 'one_page'],
            $options->toArray(),
        );
    }

    #[Test]
    public function blocks_keep_the_legend_on_by_default_and_the_query_still_rules(): void
    {
        $blocks = InstrumentGridOptions::fromQuery(['disposicao' => 'blocos']);
        $this->assertSame(InstrumentGridLayout::BLOCKS, $blocks->layout);
        $this->assertTrue($blocks->legend);

        $this->assertTrue(InstrumentGridOptions::fromQuery(['legenda' => '1'])->legend);
        $this->assertFalse(InstrumentGridOptions::fromQuery(['disposicao' => 'blocos', 'legenda' => '0'])->legend);
    }

    #[Test]
    public function a_forged_layout_is_the_default_and_with_layout_returns_a_copy(): void
    {
        $this->assertSame(InstrumentGridLayout::ONE_PAGE, InstrumentGridOptions::fromQuery(['disposicao' => 'x'])->layout);
        $this->assertSame(InstrumentGridLayout::ONE_PAGE, InstrumentGridOptions::fromQuery(['disposicao' => ['a']])->layout);

        $original = InstrumentGridOptions::fromQuery(['bruta' => '0']);
        $pdf = $original->withLayout(InstrumentGridLayout::BLOCKS);

        $this->assertSame(InstrumentGridLayout::BLOCKS, $pdf->layout);
        $this->assertFalse($pdf->rawScore);
        $this->assertSame(InstrumentGridLayout::ONE_PAGE, $original->layout);
    }
```

O teste antigo `everything_is_on_and_the_orientation_automatic_by_default` é **substituído** pelo primeiro acima (o default mudou de propósito).

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=InstrumentGridOptionsTest`
Expected: FAIL — propriedade `layout` inexistente.

- [ ] **Step 3: Implementar**

```php
final readonly class InstrumentGridOptions
{
    public function __construct(
        public bool $rawScore = true,
        public bool $result = true,
        public bool $domains = true,
        /** Desligada por omissão em `one_page`: a descrição das questões não cabe numa página. */
        public bool $legend = false,
        public bool $charts = false,
        /** @var 'auto'|'portrait'|'landscape' */
        public string $orientation = InstrumentGridLayout::AUTO,
        /** @var 'blocks'|'one_page' */
        public string $layout = InstrumentGridLayout::ONE_PAGE,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $layout = match ($query['disposicao'] ?? null) {
            'blocos' => InstrumentGridLayout::BLOCKS,
            default => InstrumentGridLayout::ONE_PAGE,
        };

        return new self(
            rawScore: self::flag($query['bruta'] ?? null),
            result: self::flag($query['resultado'] ?? null),
            domains: self::flag($query['dominios'] ?? null),
            legend: self::flag($query['legenda'] ?? null, default: $layout === InstrumentGridLayout::BLOCKS),
            charts: self::flag($query['graficos'] ?? null, default: false),
            orientation: match ($query['orientacao'] ?? null) {
                'vertical' => InstrumentGridLayout::PORTRAIT,
                'horizontal' => InstrumentGridLayout::LANDSCAPE,
                default => InstrumentGridLayout::AUTO,
            },
            layout: $layout,
        );
    }

    /** As mesmas opções com outra disposição — o PDF força sempre os blocos. */
    public function withLayout(string $layout): self
    {
        return new self($this->rawScore, $this->result, $this->domains, $this->legend, $this->charts, $this->orientation, $layout);
    }

    /**
     * @return array{raw_score: bool, result: bool, domains: bool, legend: bool, charts: bool, orientation: string, layout: string}
     */
    public function toArray(): array
    {
        return [
            'raw_score' => $this->rawScore,
            'result' => $this->result,
            'domains' => $this->domains,
            'legend' => $this->legend,
            'charts' => $this->charts,
            'orientation' => $this->orientation,
            'layout' => $this->layout,
        ];
    }
```

Atualizar o docblock da classe: acrescentar a linha `disposicao  uma-pagina (por omissão) | blocos — a pré-visualização encolhe um só bloco; o PDF usa sempre blocos` e corrigir «They are all ON by default» para dizer que a legenda está OFF em `uma-pagina`.

- [ ] **Step 4: Correr os testes, Pint, PHPStan**

```bash
php artisan test --filter=InstrumentGridOptionsTest
vendor/bin/pint --test app/Services/Reporting/ClassDocuments/InstrumentGridOptions.php tests/Unit/Reporting/InstrumentGridOptionsTest.php
vendor/bin/phpstan analyse app/Services/Reporting/ClassDocuments/InstrumentGridOptions.php --memory-limit=1G
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reporting/ClassDocuments/InstrumentGridOptions.php tests/Unit/Reporting/InstrumentGridOptionsTest.php
git commit -m "feat(grid-document): opção «disposicao» (uma-pagina por omissão, blocos)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: `InstrumentGrid` — siglas e legenda no documento; PDF força blocos

**Files:**
- Modify: `app/Services/Reporting/ClassDocuments/InstrumentGrid.php:131-139` (colunas de item), `:154-158` (plan), `:211-230` (payload), `:262-290` (colunas-resumo), `:412-450` (células de nível/domínio), `:513-516` (payload da coluna-resumo), `:528-556` (group spans)
- Modify: `app/Http/Controllers/InstrumentGridDocumentController.php:78-85` e `:138-153`
- Test: `tests/Feature/Reporting/InstrumentGridDocumentTest.php`

**Interfaces:**
- Consumes: `Abbreviations` (Task 3), `InstrumentGridLayout::ONE_PAGE` (Task 5), `InstrumentGridOptions::$layout`/`withLayout` (Task 6).
- Produces, no JSON do documento (aditivo; o Blade do PDF não lê nada disto):
  - cada coluna: `short` (string) — pergunta: número; resumo: sigla fixa; domínio: `domainShort` sem colisões;
  - cada `group_spans[]`: `short` (string|null);
  - células de `level` e `domain`: `short` (string|null) — sigla da menção (`uniqueMentionShorts` sobre as bandas da escala) para o texto/detalhe da banda;
  - `layout`: `'one_page'|'blocks'`;
  - `abbreviations`: `list<array{short: string, long: string}>` — grupos com rótulo, domínios, colunas-resumo cuja sigla difere do rótulo, menções (quando há bandas), sem repetidos, por ordem de aparecimento.

- [ ] **Step 1: Testes** (acrescentar a `InstrumentGridDocumentTest`; usar os helpers `props()` e `scenario()` já existentes — o cenário tem o instrumento «Teste 1» com perguntas, grupos nomeados e dois domínios; ler `scenario()` l.91-153 para os nomes exatos e ajustar as asserções de texto)

```php
    #[Test]
    public function one_page_is_the_default_and_the_document_carries_shorts_and_their_legend(): void
    {
        $props = $this->props();
        $document = $props['document'];

        $this->assertSame('one_page', $document['layout']);
        $this->assertSame('one_page', $document['options']['layout']);
        $this->assertFalse($document['options']['legend']);
        $this->assertNull($document['legend']['questions']);
        $this->assertSame('landscape', $document['orientation']);
        $this->assertCount(1, $document['blocks']);

        $columns = $document['blocks'][0]['columns'];
        $item = collect($columns)->firstWhere('kind', 'item');
        $this->assertSame(Abbreviations::questionNumber($item['code']), $item['short']);
        $this->assertSame('Bruta', collect($columns)->firstWhere('kind', 'raw')['short']);
        $this->assertSame('Res.', collect($columns)->firstWhere('kind', 'result')['short']);

        $domain = collect($columns)->firstWhere('kind', 'domain');
        $this->assertSame(Abbreviations::domainShort($domain['label']), $domain['short']);

        $longs = array_column($document['abbreviations'], 'long');
        $this->assertContains($domain['label'], $longs);
        $this->assertContains('Pontuação bruta', $longs);
        $this->assertSame(count($longs), count(array_unique(array_column($document['abbreviations'], 'short'))), 'siglas repetidas');

        foreach ($document['blocks'][0]['group_spans'] ?? [] as $span) {
            $this->assertSame(Abbreviations::groupShort($span['label']), $span['short']);
        }
    }

    #[Test]
    public function blocks_are_still_available_and_keep_the_legend(): void
    {
        $document = $this->props('?disposicao=blocos')['document'];

        $this->assertSame('blocks', $document['layout']);
        $this->assertTrue($document['options']['legend']);
        $this->assertIsArray($document['legend']['questions']);
    }

    #[Test]
    public function the_pdf_always_uses_blocks_whatever_the_query_says(): void
    {
        $bytes = $this->pdf('?disposicao=uma-pagina');

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(
            'blocks',
            app(InstrumentGrid::class)->build($this->teste1(), InstrumentGridOptions::fromQuery(['disposicao' => 'uma-pagina'])->withLayout(InstrumentGridLayout::BLOCKS))['layout'],
        );
    }
```

Atualizar as asserções exatas de `options` nos testes existentes (l.200 e l.221): acrescentar `'layout' => 'one_page'` no fim de cada array e, em l.221 (`forged_options_are_the_default…`), `'legend' => false`. Em l.197-205 (`the_options_in_the_query…`) a query tem `legenda=0`, por isso `legend` continua `false` e `layout` é `'one_page'`.

Acrescentar `use App\Support\Documents\Abbreviations;` e `use App\Services\Reporting\ClassDocuments\InstrumentGridLayout;` ao topo do teste (o `InstrumentGrid` e `InstrumentGridOptions` já lá estão — confirmar).

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=InstrumentGridDocumentTest`
Expected: FAIL — `layout`/`short`/`abbreviations` ausentes; os testes antigos de `options` falham por falta de `layout`.

- [ ] **Step 3: Implementar em `InstrumentGrid.php`**

(a) Import: `use App\Support\Documents\Abbreviations;`

(b) Colunas de item (l.131-139): acrescentar `'short' => Abbreviations::questionNumber($item->code),` ao array de cada item.

(c) Colunas-resumo (`summaryColumns`, l.262-290): acrescentar `'short'` a cada coluna. Para `raw`/`result`/`level`: `'short' => Abbreviations::summaryShort('raw')` etc. Para domínios, dentro do ciclo que cria as colunas de domínio, manter uma lista `$taken = []` e fazer:

```php
                $short = Abbreviations::domainShort($domain['name'], $taken);
                $taken[] = $short;
                $columns[] = ['kind' => 'domain', 'label' => $domain['name'], 'short' => $short, 'quota' => null, 'domain_key' => 'd'.$domain['id']];
```

(d) `summaryColumnPayload` (l.513-516): `return ['kind' => $column['kind'], 'label' => $column['label'], 'short' => $column['short'], 'quota' => $column['quota']];` e atualizar o `@param` para incluir `short: string`.

(e) Siglas das menções: em `build()`, logo após `$hasBands = …`:

```php
        $mentionShorts = Abbreviations::uniqueMentionShorts(array_map(
            fn ($band): array => ['code' => (string) $band->code, 'label' => (string) $band->label],
            $context->bands(),
        ));
```
Passar `$mentionShorts` a `row()` (novo parâmetro `array $mentionShorts`) e daí a `levelCell($officialCells, $mentionShorts)` e `domainCell($officialCells, $domainKey, $mentionShorts)`. Em `levelCell`: `'short' => $label === null ? null : ($mentionShorts[$label] ?? null)`. Em `domainCell`: `'short' => isset($cell['band']['label']) ? ($mentionShorts[$cell['band']['label']] ?? null) : null` no ramo com percentagem, `'short' => null` no ramo sem. Nas células de item e de `raw`/`result` acrescentar `'short' => null` para a forma ser sempre a mesma.

(f) Plano (l.154-158): passar o layout:

```php
        $plan = InstrumentGridLayout::plan(
            $groupIds,
            array_map(fn (array $column): float => $this->widthOf($column['kind']), $summaryColumns),
            $options->orientation,
            InstrumentGridLayout::ITEM_WIDTH,
            $options->layout,
        );
```

(g) `groupSpans` (l.528-556): no `array_map` final devolver `['label' => $label, 'short' => $label === null ? null : Abbreviations::groupShort($label), 'span' => $span]` e atualizar o `@return`.

(h) Legenda das siglas — método novo:

```php
    /**
     * Cada sigla do papel por extenso, uma vez, por ordem de aparecimento:
     * grupos, perguntas não são siglas (o número é o código), colunas-resumo,
     * domínios e menções da escala.
     *
     * @param  list<array<string, mixed>>  $itemColumns
     * @param  list<array{kind: string, label: string, short: ?string, quota: string|null, domain_key: string|null}>  $summaryColumns
     * @param  array<string, string>  $mentionShorts
     * @return list<array{short: string, long: string}>
     */
    protected function abbreviations(array $itemColumns, array $summaryColumns, array $mentionShorts): array
    {
        $entries = [];
        $seen = [];
        $add = function (?string $short, ?string $long) use (&$entries, &$seen): void {
            if ($short === null || $short === '' || $long === null || $short === $long || isset($seen[$short])) {
                return;
            }

            $seen[$short] = true;
            $entries[] = ['short' => $short, 'long' => $long];
        };

        foreach ($itemColumns as $column) {
            $add(Abbreviations::groupShort($column['group'] ?? null), $column['group'] ?? null);
        }

        foreach ($summaryColumns as $column) {
            $add($column['short'], $column['label']);
        }

        foreach ($mentionShorts as $label => $short) {
            $add($short, (string) $label);
        }

        return $entries;
    }
```

(i) Payload (l.211-230): acrescentar `'layout' => $options->layout,` e `'abbreviations' => $this->abbreviations($itemColumns, $summaryColumns, $mentionShorts),`.

- [ ] **Step 4: Controlador**

Em `InstrumentGridDocumentController`, mudar `document()` para receber as opções:

```php
    protected function document(Request $request, Instrument $instrument, bool $forPdf = false): array|RedirectResponse
    {
        Gate::authorize('view', $instrument->schoolClass);

        if ($instrument->status === InstrumentStatus::Draft) {
            return to_route('instruments.edit', $instrument->ulid);
        }

        $options = InstrumentGridOptions::fromQuery($request->query());

        // O dompdf não escala nem pagina colunas: o PDF é sempre por blocos,
        // e a legenda das questões volta ao seu default (ligada) só se a
        // query não disser nada — `fromQuery` já tratou disso para blocos.
        if ($forPdf) {
            $options = InstrumentGridOptions::fromQuery([...$request->query(), 'disposicao' => 'blocos']);
        }

        try {
            return $this->grid->build($instrument, $options);
        } catch (InstrumentGridUnavailable $exception) {
            // … inalterado …
        }
    }
```
e em `pdf()`: `$document = $this->document($request, $instrument, forPdf: true);`. (`withLayout` fica disponível para quem já tem as opções; o controlador prefere refazer a leitura para a legenda voltar ao default dos blocos.)

Acrescentar ao registo de auditoria em `pdf()` a propriedade `'layout' => $document['layout'],`.

- [ ] **Step 5: Correr os testes, Pint, PHPStan**

```bash
php artisan test --filter="InstrumentGridDocumentTest|InstrumentGridTest|InstrumentGridChartsTest|InstrumentGridValidationTest"
vendor/bin/pint --test app/Services/Reporting/ClassDocuments/InstrumentGrid.php app/Http/Controllers/InstrumentGridDocumentController.php tests/Feature/Reporting/InstrumentGridDocumentTest.php
vendor/bin/phpstan analyse app/Services/Reporting/ClassDocuments app/Http/Controllers/InstrumentGridDocumentController.php --memory-limit=1G
```
Expected: PASS. Se `InstrumentGridTest` comparar o payload de uma coluna ou célula por igualdade exata, acrescentar `'short'` ao esperado (é o único campo novo).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Reporting/ClassDocuments/InstrumentGrid.php app/Http/Controllers/InstrumentGridDocumentController.php tests/Feature/Reporting/InstrumentGridDocumentTest.php tests/Unit/Reporting/InstrumentGridTest.php
git commit -m "feat(grid-document): siglas e legenda no documento; o PDF força blocos

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```
(só incluir `InstrumentGridTest.php` se tiver sido alterado.)

---

### Task 8: Versão 0.191.0, CHANGELOG e documentação

**Files:**
- Modify: `config/app.php:28`, `CHANGELOG.md` (topo), `docs/reports-class-documents.md` (secção «instrumento, impressão e PDF», l.302-335)
- Test: `tests/Feature/Release/ReleaseVersionTest.php` (existente)

- [ ] **Step 1: Versão e CHANGELOG**

`config/app.php`: `'version' => '0.191.0',`.

Topo do `CHANGELOG.md`, antes de `## [0.190.2]`:

```markdown
## [0.191.0] — 2026-11-07

> **Grelha de correção: imprime-se numa página.** Ao imprimir pelo browser, a
> grelha de um elemento de avaliação saía em várias páginas — as perguntas
> partidas em blocos, cada bloco na sua folha, e a legenda das questões no
> fim —, e a leitura de conjunto perdia-se. Passa a sair, por omissão, numa
> só A4 horizontal: um bloco com todas as colunas, cabeçalhos em sigla (o
> número da pergunta, «G.V», «Edu. Lit.») com legenda de uma linha, e a letra
> a encolher até 6 pt. Quando nem assim cabe, as linhas seguem para a página
> seguinte com o cabeçalho repetido; as colunas nunca se partem. A disposição
> em blocos continua disponível no seletor «Disposição». O PDF do servidor
> não muda. Sem migrations.

### Adicionado

- `printFit`: ajuste de uma tabela a uma página (largura e altura, piso de
  6 pt), em funções puras, para reutilizar na pauta e nas intercalares.
- `Abbreviations` (PHP) e `abbreviations.ts`: a mesma regra de siglas nos dois
  lados, com os mesmos casos de teste.
- `PrintableDocument`: prop `fit` que publica a escala como variável CSS.
- Grelha do elemento de avaliação: disposição `uma-pagina` (por omissão) ou
  `blocos`; siglas e legenda no documento; aviso no ecrã com a escala aplicada.

### Alterado

- Em `uma-pagina` a legenda das questões vem desligada por omissão; em
  `blocos` continua ligada. O PDF usa sempre `blocos`.
```

`docs/reports-class-documents.md`, no fim da secção sobre a impressão do instrumento, acrescentar:

```markdown
#### Uma página (só no browser)

Desde a 0.191.0 a pré-visualização pede por omissão `disposicao=uma-pagina`:
`InstrumentGridLayout` devolve um só bloco em paisagem e a página mede a tabela
e encolhe-a (`resources/js/lib/printFit.ts`, por `zoom` numa variável CSS do
`PrintableDocument`) até caber em 277 × 190 mm, nunca abaixo de 6 pt. Os
cabeçalhos usam siglas (`App\Support\Documents\Abbreviations`) com legenda de
uma linha. O PDF do servidor usa sempre `disposicao=blocos`: o dompdf não
escala nem pagina colunas.
```

- [ ] **Step 2: Correr o teste de versão e o Pint**

```bash
php artisan test --filter=ReleaseVersionTest
vendor/bin/pint --test config/app.php
```
Expected: PASS 5/5.

- [ ] **Step 3: Commit**

```bash
git add config/app.php CHANGELOG.md docs/reports-class-documents.md
git commit -m "chore(release): Lapispro 0.191.0 — grelha de correção numa página

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: `instruments/GridDocument.vue` — seletor, tabela compacta, medição e aviso

**Files:**
- Modify: `resources/js/pages/instruments/GridDocument.vue`
- Test: `resources/js/pages/instruments/GridDocument.test.ts`

**Interfaces:**
- Consumes: `fitToPage`, `fitNotice`, `usableAreaPx` (Task 1); prop `fit` e classe `document-print-fit` (Task 4); campos `short`, `layout`, `abbreviations`, `options.layout` (Task 7).
- Produces: query `disposicao=uma-pagina|blocos`; chave `localStorage` `lapis:print-layout:instrument-grid` com `one_page`/`blocks`.

- [ ] **Step 1: Testes** (acrescentar ao `describe('instruments/GridDocument.vue')`; o `makeDocument` de l.63 ganha `layout: 'one_page'`, `abbreviations: []` e `options.layout: 'one_page'`; as colunas do `makeBlock` ganham `short` — `'1'`, `'2'`, `'Bruta'`, `'Res.'`, `'Nív.'`, `'Geom.'`; as células ganham `short: null`, e a célula de nível `short: 'MB'`, a de domínio `short: 'B'`; `group_spans` quando usado ganha `short`)

```ts
    it('em «uma página» mostra os cabeçalhos em sigla, uma legenda das siglas e a tabela marcada para o ajuste', () => {
        const wrapper = mountPage({
            layout: 'one_page',
            abbreviations: [
                { short: 'Geom.', long: 'Geometria' },
                { short: 'Bruta', long: 'Pontuação bruta' },
            ],
        });

        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers).toContain('1');
        expect(headers).toContain('Bruta');
        expect(headers).not.toContain('Q1');
        expect(headers).not.toContain('Pontuação bruta');

        const fit = wrapper.find('.document-print-fit');
        expect(fit.exists()).toBe(true);
        expect(fit.text()).toContain('Geom. = Geometria');
        expect(fit.text()).toContain('Bruta = Pontuação bruta');
    });

    it('em «uma página» a célula de nível e o detalhe do domínio saem em sigla', () => {
        const wrapper = mountPage({ layout: 'one_page' });
        const cells = wrapper.findAll('tbody td').map((td) => td.text());

        expect(cells).toContain('MB');
        expect(cells.some((text) => text.includes('80,0 %') && text.includes('B') && !text.includes('Bom'))).toBe(true);
    });

    it('em «blocos» mantém os cabeçalhos por extenso e sem legenda das siglas', () => {
        const wrapper = mountPage({ layout: 'blocks', options: { ...makeDocument().options, layout: 'blocks' } });
        const headers = wrapper.findAll('thead th').map((th) => th.text());

        expect(headers).toContain('Q1');
        expect(headers).toContain('Pontuação bruta');
        expect(wrapper.find('[data-abbreviations]').exists()).toBe(false);
    });

    it('muda a disposição pedindo ao servidor com a palavra que ele lê, e lembra-a neste browser', async () => {
        const wrapper = mountPage();

        await wrapper.find('#grid-doc-layout').setValue('blocks');

        expect(routerGet).toHaveBeenCalledWith(
            '/instruments/inst-1/impressao',
            expect.objectContaining({ disposicao: 'blocos' }),
            expect.anything(),
        );
        expect(localStorage.getItem('lapis:print-layout:instrument-grid')).toBe('blocks');
    });

    it('envia a disposição atual em todas as outras mudanças de opção e no PDF', async () => {
        const wrapper = mountPage();

        await wrapper.find('#grid-doc-orientation').setValue('landscape');
        expect(routerGet).toHaveBeenCalledWith(
            '/instruments/inst-1/impressao',
            expect.objectContaining({ disposicao: 'uma-pagina', orientacao: 'horizontal' }),
            expect.anything(),
        );
        expect(wrapper.find('a[href*="/pdf?"]').attributes('href')).toContain('disposicao=uma-pagina');
    });

    it('ao abrir com uma disposição lembrada diferente da servida, pede-a ao servidor uma vez', () => {
        localStorage.setItem('lapis:print-layout:instrument-grid', 'blocks');
        mountPage();

        expect(routerGet).toHaveBeenCalledWith(
            '/instruments/inst-1/impressao',
            expect.objectContaining({ disposicao: 'blocos' }),
            expect.objectContaining({ replace: true }),
        );
    });

    it('a orientação fica inativa em «uma página», que é sempre horizontal', () => {
        const wrapper = mountPage();

        expect(wrapper.find('#grid-doc-orientation').attributes('disabled')).toBeDefined();
    });

    it('no jsdom nada se mede: a escala publicada é 1 e não há aviso', () => {
        const wrapper = mountPage();

        expect(wrapper.find('.document-print').attributes('style') ?? '').toContain('--document-print-zoom: 1');
        expect(wrapper.find('[data-fit-notice]').exists()).toBe(false);
    });
```

No `afterEach` existente acrescentar `localStorage.clear();` e `routerGet.mockClear();` (confirmar se já lá está — se sim, não duplicar).

Testes existentes a ajustar: «prints exactly what the server composed: every cell text, header…» (l.167) compara cabeçalhos por extenso — passar o documento com `layout: 'blocks'` nesse teste, ou verificar `short`s; «draws one table per block…» (l.351) usa `blocks` com várias partes — passar `layout: 'blocks'`; «wraps the sheet in the print hook with the orientation the server chose» (l.369) continua válido.

- [ ] **Step 2: Correr para ver falhar**

Run: `npx vitest run resources/js/pages/instruments/GridDocument.test.ts`
Expected: FAIL nos testes novos (sem `#grid-doc-layout`, sem `.document-print-fit`, cabeçalhos por extenso).

- [ ] **Step 3: Implementar — script**

Tipos: em `Column` acrescentar `short: string;`; em `Cell` acrescentar `short: string | null;`; em `Block.group_spans` o item passa a `{ label: string | null; short: string | null; span: number }`; em `GridDocument` acrescentar `layout: 'one_page' | 'blocks';`, `abbreviations: { short: string; long: string }[];` e em `options` `layout: 'one_page' | 'blocks';`.

Imports novos: `import { computed, nextTick, onMounted, ref, watch } from 'vue';` e `import { fitNotice, fitToPage, usableAreaPx } from '@/lib/printFit';`.

Query:

```ts
type Layout = 'one_page' | 'blocks';

const layoutQuery: Record<Layout, string> = { one_page: 'uma-pagina', blocks: 'blocos' };

const LAYOUT_STORAGE_KEY = 'lapis:print-layout:instrument-grid';

function query(options: GridDocument['options']): Record<string, string> {
    return {
        bruta: options.raw_score ? '1' : '0',
        resultado: options.result ? '1' : '0',
        dominios: options.domains ? '1' : '0',
        legenda: options.legend ? '1' : '0',
        graficos: options.charts ? '1' : '0',
        orientacao: orientationQuery[options.orientation],
        disposicao: layoutQuery[options.layout],
    };
}

const isOnePage = computed(() => props.document.layout === 'one_page');

function rememberedLayout(): Layout | null {
    try {
        const value = localStorage.getItem(LAYOUT_STORAGE_KEY);

        return value === 'one_page' || value === 'blocks' ? value : null;
    } catch {
        return null;
    }
}

function rememberLayout(layout: Layout): void {
    try {
        localStorage.setItem(LAYOUT_STORAGE_KEY, layout);
    } catch {
        // O armazenamento do browser é opcional.
    }
}

function setLayout(value: string | number | null): void {
    if (value !== 'one_page' && value !== 'blocks') {
        return;
    }

    rememberLayout(value);
    router.get(baseUrl.value, query({ ...props.document.options, layout: value }), {
        replace: true,
        preserveScroll: true,
        preserveState: true,
    });
}

function headerOf(column: Column): string {
    if (isOnePage.value) {
        return column.short;
    }

    return column.kind === 'item' ? (column.code ?? '') : (column.label ?? '');
}

/** O texto de uma célula no papel compacto: a sigla do nível quando existe. */
function cellText(cell: Cell): string {
    return isOnePage.value && cell.short !== null && cell.detail === null ? cell.short : cell.text;
}

function cellDetail(cell: Cell): string | null {
    if (!isOnePage.value) {
        return cell.detail;
    }

    return cell.short ?? cell.detail;
}
```

Medição e ajuste:

```ts
const printDocumentRef = ref<InstanceType<typeof PrintableDocument> | null>(null);
const fitRef = ref<HTMLElement | null>(null);
const headerRef = ref<HTMLElement | null>(null);
const fit = ref<number>(1);
const notice = ref<string | null>(null);

/** Mede o DOM e decide em `printFit`; no jsdom tudo mede 0 e a escala fica 1. */
function measure(): void {
    if (!isOnePage.value) {
        fit.value = 1;
        notice.value = null;

        return;
    }

    const table = fitRef.value;
    const header = headerRef.value;

    if (table === null) {
        return;
    }

    // A largura NATURAL: a tabela tem `w-full`; mede-se com a largura solta,
    // como no Quadro, senão o contentor do ecrã decidiria o papel.
    const previousWidth = table.style.width;
    table.style.width = 'max-content';
    const tableWidth = table.scrollWidth;
    const tableHeight = table.scrollHeight;
    table.style.width = previousWidth;

    const result = fitToPage({
        tableWidth,
        tableHeight,
        headerHeight: header?.offsetHeight ?? 0,
        usable: usableAreaPx('A4', 'landscape'),
    });

    fit.value = result.scale;
    notice.value = fitNotice(result);
}

onMounted(() => {
    const remembered = rememberedLayout();

    if (remembered !== null && remembered !== props.document.options.layout) {
        router.get(baseUrl.value, query({ ...props.document.options, layout: remembered }), {
            replace: true,
            preserveScroll: true,
            preserveState: true,
        });

        return;
    }

    void nextTick(measure);
});

watch(() => props.document, () => void nextTick(measure), { deep: false });

function print(): void {
    measure();
    void nextTick(() => window.print());
}
```

- [ ] **Step 4: Implementar — template**

Seletor, logo a seguir ao `<label>` da orientação:

```vue
                <label class="grid gap-1 text-sm">
                    <span class="text-xs font-medium text-muted-foreground">Disposição</span>
                    <NativeSelect
                        id="grid-doc-layout"
                        class="min-h-11 sm:min-h-9"
                        :model-value="document.options.layout"
                        @update:model-value="setLayout"
                    >
                        <option value="one_page">Uma página</option>
                        <option value="blocks">Blocos (várias páginas)</option>
                    </NativeSelect>
                </label>
```
e no `NativeSelect` da orientação acrescentar `:disabled="isOnePage"`.

Aviso de escala (depois do parágrafo «Gerado na hora…»):

```vue
            <p v-if="notice" data-fit-notice class="text-xs text-muted-foreground" role="status">{{ notice }}</p>
```

`PrintableDocument`: acrescentar `ref="printDocumentRef"`, `:fit="fit"`, e `:orientation="isOnePage ? 'landscape' : document.orientation"`, `:compact="isOnePage || document.orientation === 'landscape'"`.

Cabeçalho do documento: envolver o `ReportLetterhead` e o `<div class="mt-8 border-b …">` num `<div ref="headerRef">`.

Tabela: envolver o `<template v-else>` dos blocos **e** a legenda das siglas num `<div ref="fitRef" class="document-print-fit">`. Dentro, na `<table>`:

```vue
                    <table
                        class="w-full border-collapse tabular-nums"
                        :class="isOnePage ? 'grid-one-page' : 'text-xs'"
                    >
```
Cabeçalhos: `{{ headerOf(column) }}` já usa a sigla; na linha de grupos `{{ isOnePage ? span.short : span.label }}`. Nome: `<th scope="row" class="px-1 py-1.5 text-left font-medium break-words" :class="isOnePage ? 'max-w-[24ch] truncate' : ''">`. Célula: `{{ cellText(cell) }}` e `<span v-if="cellDetail(cell)" :class="isOnePage ? 'ml-0.5 font-normal' : 'block text-[0.65rem] font-normal text-muted-foreground'">{{ cellDetail(cell) }}</span>`.

Legenda das siglas, dentro do `fitRef`, depois dos blocos:

```vue
            <p v-if="isOnePage && document.abbreviations.length > 0" data-abbreviations class="mt-2 text-[7pt] leading-snug text-muted-foreground">
                <template v-for="(entry, index) in document.abbreviations" :key="entry.short">
                    <span class="font-medium text-foreground">{{ entry.short }}</span> = {{ entry.long }}<template v-if="index < document.abbreviations.length - 1"> · </template>
                </template>
            </p>
```

A legenda «Questões» (`document.legend.questions`) fica fora do `fitRef`, e em `isOnePage` ganha `print:break-before-page`.

CSS da tabela compacta (bloco `<style>` no fim do ficheiro, sem `@page` e sem `body *`):

```css
<style>
/* A tabela do modo «Uma página»: letra de 7,5 pt antes da escala, colunas ao
   mínimo que os números pedem, linhas baixas. Vale no ecrã e no papel, para a
   pré-visualização ser o que se imprime. */
.grid-one-page {
    font-size: 7.5pt;
    line-height: 1.15;
}
.grid-one-page th,
.grid-one-page td {
    padding: 1pt 2pt;
    border-width: 0.5pt;
}
.grid-one-page th[scope='col'],
.grid-one-page td {
    min-width: 3ch;
    white-space: nowrap;
}
.grid-one-page th[scope='row'] {
    white-space: nowrap;
}
</style>
```

- [ ] **Step 5: Correr os testes**

Run: `npx vitest run resources/js/pages/instruments/GridDocument.test.ts resources/js/lib/printStyles.test.ts resources/js/components/documents/PrintableDocument.test.ts`
Expected: PASS. Se «no jsdom nada se mede» falhar por `style` ausente: o `fit` inicial é 1 e a prop publica `--document-print-zoom: 1` — confirmar que o `PrintableDocument` recebe `:fit="fit"` e que `fit` não é `null`.

- [ ] **Step 6: ESLint, tipos, commit**

```bash
npx eslint resources/js/pages/instruments/GridDocument.vue resources/js/pages/instruments/GridDocument.test.ts
npm run types:check
git add resources/js/pages/instruments/GridDocument.vue resources/js/pages/instruments/GridDocument.test.ts
git commit -m "feat(grid-document): modo «Uma página» na pré-visualização — siglas, legenda e ajuste à folha

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 10: Gates completos, QA visual e PR

**Files:**
- Nenhum novo no repositório. Guião de QA em `C:\Users\PCUser\AppData\Local\Temp\claude\C--Users-PCUser\0465479a-3fe1-4db3-a0c4-8efc0f01705a\scratchpad\qa-print\` (fora do projeto).

- [ ] **Step 1: Gates**

```bash
cd /d/worktrees/lapis-draft-recovery && export PATH="/c/Users/PCUser/.config/herd/bin/php84:$PATH"
php artisan view:clear && npm run build
npm run types:check && npm run lint:check
npx vitest run
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=2G
php artisan test --filter="Reporting|Documents|Release|InstrumentGrid"
```
Expected: tudo verde. Registar as contagens (ficheiros/testes do vitest; testes do phpunit).

- [ ] **Step 2: QA visual no browser isolado** (ver memória `lapispro-qa-browser-isolado`: `artisan serve` + SQLite + Playwright)

Guião Python (`qa-print/qa_print.py`): entrar com a conta de QA, criar um instrumento de 30 perguntas em 5 grupos («Grupo I»…«Grupo V») numa turma de 28 alunos com dois domínios, marcar valores, abrir `/instruments/{ulid}/impressao`, e:

1. Verificar que existe um só `[data-block]` e `#grid-doc-layout` com valor `one_page`.
2. `page.emulate_media(media='print')` e `page.pdf(path='one-page.pdf', format='A4', landscape=True, margin={'top':'10mm','right':'10mm','bottom':'10mm','left':'10mm'}, prefer_css_page_size=True)`; contar as páginas com `pypdf` — esperado **1**.
3. Repetir com `?disposicao=blocos` — esperado > 1 página (o comportamento antigo).
4. Guardar capturas `one-page.png` (ecrã) para o relatório.

Expected: 1 página em «Uma página»; a captura mostra siglas e legenda.

- [ ] **Step 3: Push e PR**

```bash
git push -u origin feat/print-one-page
gh pr create --base main --head feat/print-one-page --title "feat(print): grelha de correção numa página (0.191.0)" --body-file -
```
Corpo da PR: problema (6 páginas), decisões (browser, 6 pt, 10 mm, só o número), o que muda (lista das Tasks 1-9), verificação (contagens dos gates e resultado do QA com o número de páginas), «Sem migrations. O PDF do servidor não muda.», e o rodapé `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.

- [ ] **Step 4: Esperar a CI e pedir autorização de merge e deploy**

Sem merge nem deploy sem autorização expressa do utilizador. Deploy só de código, pelo guião da 0.190.2 adaptado (OLD = `update-0.190.2.tgz`, sha `a1e42709…`, 4155 entradas).

---

## Self-review

- **Cobertura da spec (PR 1):** §4 ajuste → Task 1 + Task 4 + Task 9; §5 siglas → Tasks 2, 3, 7; §6 grelha → Tasks 5, 6, 7, 9; §9 PDF → Task 7 (controlador); §10 testes → em cada task + Task 10; §11 entrega/versão → Tasks 8 e 10. §7 (pauta) e §8 (intercalares) ficam para as PRs 2 e 3, com `abbreviations.ts` e `printFit.ts` já prontos.
- **Placeholders:** nenhum «TBD»; os testes de Task 7 dependem dos nomes do `scenario()` existente e dizem onde os ler.
- **Consistência de nomes:** `one_page`/`blocks` (constantes e JSON), `uma-pagina`/`blocos` (query), `short` (colunas, spans, células), `abbreviations` (documento), `document-print-fit` (classe), `--document-print-zoom` (variável), `lapis:print-layout:instrument-grid` (storage), `#grid-doc-layout` (seletor) — iguais em todas as tasks.
