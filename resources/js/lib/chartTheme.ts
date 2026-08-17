/**
 * The LÁPIS chart design system.
 *
 * Chart.js is the drawing engine and nothing else. Everything about how a chart
 * LOOKS is decided here, once, so that five charts on a page are recognisably
 * the same family rather than five defaults that happen to share a screen.
 *
 * THREE VISUAL LANGUAGES, NEVER MIXED (§7):
 *
 *   DESEMPENHO — the qualitative scale's own tones. Placed by qualitativeToneFor,
 *   which reads a band's structure and never its label.
 *   EVOLUÇÃO — emerald up, rose down, neutral flat. The same inks Resultados
 *   already uses for a trend.
 *   ESTRUTURA — the domain palette and the page's own greys. These say «this
 *   line is Gramática», never «Gramática is doing badly».
 *
 * A structural red line that read as «regression» would be the single worst
 * thing this page could do, so the domain palette deliberately contains no red.
 */

import type { Chart as ChartType, TooltipModel } from 'chart.js';

// ---------------------------------------------------------------- tipografia

/**
 * The page's own type stack, so a chart's labels are set in the same face as
 * the sentence above them. Chart.js cannot inherit CSS, so it is told.
 */
export const CHART_FONT = {
    family: 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
    size: 12,
    weight: 500 as const,
};

// ------------------------------------------------------------------- chrome

export type Chrome = {
    text: string;
    muted: string;
    grid: string;
    surface: string;
    border: string;
};

export function isDark(): boolean {
    return typeof document !== 'undefined' && document.documentElement.classList.contains('dark');
}

/**
 * The neutral chrome, read from the live document.
 *
 * A canvas cannot inherit a CSS variable, so the tokens are resolved at draw
 * time — and resolved again whenever the theme changes underneath it.
 */
export function chromeColours(): Chrome {
    const dark = isDark();

    if (typeof window === 'undefined' || typeof getComputedStyle !== 'function') {
        return { text: '#0a0a0a', muted: '#737373', grid: 'rgba(0,0,0,0.06)', surface: '#ffffff', border: 'rgba(0,0,0,0.10)' };
    }

    const styles = getComputedStyle(document.documentElement);
    const read = (token: string, fallback: string): string => styles.getPropertyValue(token).trim() || fallback;

    return {
        text: read('--foreground', dark ? '#fafafa' : '#0a0a0a'),
        muted: read('--muted-foreground', dark ? '#a3a3a3' : '#737373'),
        // Deliberately faint. A grid is a reading aid, not part of the data —
        // heavy rules are most of what makes a chart look like a spreadsheet.
        grid: dark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.055)',
        surface: read('--card', dark ? '#0a0a0a' : '#ffffff'),
        border: dark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.10)',
    };
}

// ---------------------------------------------------------------- desempenho

import type { QualitativeTone } from '@/lib/qualitativeTone';

/** Performance tones, matched to the families qualitativeToneClasses paints. */
export const TONE_COLOURS: Record<QualitativeTone, { fill: string; border: string; soft: string }> = {
    green: { fill: 'rgba(16,185,129,0.82)', border: 'rgb(5,150,105)', soft: 'rgba(16,185,129,0.18)' },
    blue: { fill: 'rgba(59,130,246,0.82)', border: 'rgb(37,99,235)', soft: 'rgba(59,130,246,0.18)' },
    amber: { fill: 'rgba(245,158,11,0.82)', border: 'rgb(217,119,6)', soft: 'rgba(245,158,11,0.18)' },
    red: { fill: 'rgba(244,63,94,0.82)', border: 'rgb(225,29,72)', soft: 'rgba(244,63,94,0.18)' },
    neutral: { fill: 'rgba(148,163,184,0.6)', border: 'rgb(100,116,139)', soft: 'rgba(148,163,184,0.16)' },
};

/** Movement. Never performance. */
export const TREND_COLOURS = {
    up: { fill: 'rgba(16,185,129,0.85)', border: 'rgb(5,150,105)' },
    down: { fill: 'rgba(244,63,94,0.85)', border: 'rgb(225,29,72)' },
    flat: { fill: 'rgba(148,163,184,0.7)', border: 'rgb(100,116,139)' },
    none: { fill: 'rgba(148,163,184,0.25)', border: 'rgba(148,163,184,0.8)' },
};

// ---------------------------------------------------------------- estrutura

/**
 * The domain palette.
 *
 * Cool and jewel-toned on purpose, and deliberately WITHOUT a red or a green
 * that could be mistaken for the trend inks. Chosen to stay distinguishable
 * from one another on both themes, and to sit quietly beside the performance
 * tones rather than compete with them.
 */
export const DOMAIN_PALETTE: string[] = [
    '#4f46e5', // indigo
    '#0891b2', // cyan
    '#7c3aed', // violet
    '#0284c7', // sky
    '#c026d3', // fuchsia
    '#0d9488', // teal
    '#e11d90', // pink
    '#6366f1', // indigo light
    '#0369a1', // deep sky
    '#a21caf', // purple
];

/**
 * The colour of each domain, decided by the domain's own id.
 *
 * DETERMINISTIC, and not by render order (§8). The same domain keeps the same
 * colour in the bar chart, in the trend lines, in the heatmap and in a
 * student's own panel — and keeps it tomorrow, after a domain is added or the
 * page is sorted differently. Collisions inside one class are resolved by
 * probing forward, so two domains of the same profile never share an ink.
 */
export function domainColours(domainIds: number[]): Record<number, string> {
    const taken = new Set<number>();
    const map: Record<number, string> = {};

    // Sorted by id so the assignment for a given set of domains cannot depend
    // on the order the caller happened to hand them over in.
    for (const id of [...domainIds].sort((a, b) => a - b)) {
        let slot = id % DOMAIN_PALETTE.length;
        let probes = 0;

        while (taken.has(slot) && probes < DOMAIN_PALETTE.length) {
            slot = (slot + 1) % DOMAIN_PALETTE.length;
            probes++;
        }

        taken.add(slot);
        map[id] = DOMAIN_PALETTE[slot];
    }

    return map;
}

/** The same colour, faded, for anything the reader is not looking at. */
export function muted(colour: string, alpha = 0.16): string {
    if (colour.startsWith('#')) {
        const value = colour.slice(1);
        const red = parseInt(value.slice(0, 2), 16);
        const green = parseInt(value.slice(2, 4), 16);
        const blue = parseInt(value.slice(4, 6), 16);

        return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
    }

    return colour.replace(/rgba?\(([^)]+)\)/, (_, inner: string) => {
        const parts = inner.split(',').map((part) => part.trim());

        return `rgba(${parts[0]}, ${parts[1]}, ${parts[2]}, ${alpha})`;
    });
}

// ---------------------------------------------------------------- animação

export function prefersReducedMotion(): boolean {
    return typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Short and eased-out. Long enough to be felt as the data arriving, short
 * enough never to be waited for — and absent entirely for a reader who asked
 * not to be animated (§6).
 */
export function animation(): { duration: number; easing: 'easeOutQuart' } {
    return { duration: prefersReducedMotion() ? 0 : 520, easing: 'easeOutQuart' };
}

// ------------------------------------------------------------------ escalas

/**
 * A percentage axis, dressed down: no border, a faint grid, and ticks that read
 * as labels rather than as furniture.
 */
export function percentAxis(chrome: Chrome, max = 100): Record<string, unknown> {
    return {
        beginAtZero: true,
        max,
        border: { display: false },
        grid: { color: chrome.grid, drawTicks: false },
        ticks: {
            color: chrome.muted,
            font: { ...CHART_FONT, size: 11, weight: 400 },
            padding: 8,
            callback: (value: string | number) => `${value}%`,
        },
    };
}

/** A category axis with no grid at all — the bars already say where they are. */
export function categoryAxis(chrome: Chrome, emphasised = false): Record<string, unknown> {
    return {
        border: { display: false },
        grid: { display: false },
        ticks: {
            color: emphasised ? chrome.text : chrome.muted,
            font: { ...CHART_FONT, size: emphasised ? 12 : 11, weight: emphasised ? 500 : 400 },
            padding: 6,
        },
    };
}

/** A count axis — whole numbers only, and no decimals invented between them. */
export function countAxis(chrome: Chrome): Record<string, unknown> {
    return {
        beginAtZero: true,
        border: { display: false },
        grid: { color: chrome.grid, drawTicks: false },
        ticks: {
            color: chrome.muted,
            font: { ...CHART_FONT, size: 11, weight: 400 },
            padding: 8,
            precision: 0,
        },
    };
}

// ------------------------------------------------------------------ tooltip

/** One line of a tooltip: a label, its value, and an optional accent. */
export type TooltipRow = {
    label: string;
    value: string;
    /** A hex/rgb swatch shown before the label, for series identity. */
    swatch?: string;
    /** 'up' | 'down' tints the value the way a trend is tinted elsewhere. */
    trend?: 'up' | 'down' | 'flat';
    /** Sets the row apart as the headline figure. */
    strong?: boolean;
};

export type TooltipContent = {
    title: string;
    subtitle?: string;
    rows: TooltipRow[];
    footer?: string;
};

/** What a chart hands over when the pointer lands on one of its points. */
export type TooltipResolver = (dataIndex: number, datasetIndex: number) => TooltipContent | null;

// --------------------------------------------------------------- formatação

/**
 * Numbers are read here EXACTLY as Resultados reads them (§38).
 *
 * `pct()` from lib/results is reused rather than re-implemented, so a value
 * cannot be «72.4%» on one screen and «72,4%» on another. The decimal point it
 * writes is the application's current convention; changing it is a decision for
 * the whole product, not for one chart.
 */
export { pct } from '@/lib/results';

/**
 * «+4,8» / «−3,1» — percentage POINTS, signed, with the same comma trendPoints()
 * already writes on Resultados. A difference between two percentages is not
 * itself a percentage, and never carries a % sign.
 */
export function formatPoints(value: string | number | null): string {
    if (value === null || value === '') {
        return '—';
    }

    const number = Number(value);

    return (number > 0 ? `+${number.toFixed(1)}` : number.toFixed(1))
        .replace('.', ',')
        .replace('-', '−');
}

/** A share of a group — distinct from a result that happens to be a percentage. */
export function formatShare(value: string | number | null): string {
    return value === null || value === '' ? '—' : `${Number(value).toFixed(1)}%`;
}

/** «6 alunos» / «1 aluno» — said properly, both ways. */
export function students(count: number): string {
    return count === 1 ? '1 aluno' : `${count} alunos`;
}

export type TooltipState = {
    visible: boolean;
    x: number;
    y: number;
    content: TooltipContent | null;
};

/**
 * Turns Chart.js's tooltip model into a position and a payload for the page's
 * own element. Nothing is drawn here — this only decides WHAT and WHERE.
 */
export function externalTooltipHandler(
    resolve: TooltipResolver,
    apply: (state: TooltipState) => void,
): (context: { chart: ChartType; tooltip: TooltipModel<never> }) => void {
    return ({ chart, tooltip }) => {
        if (tooltip.opacity === 0 || tooltip.dataPoints === undefined || tooltip.dataPoints.length === 0) {
            apply({ visible: false, x: 0, y: 0, content: null });

            return;
        }

        const point = tooltip.dataPoints[0];
        const content = resolve(point.dataIndex, point.datasetIndex);

        if (content === null) {
            apply({ visible: false, x: 0, y: 0, content: null });

            return;
        }

        apply({
            visible: true,
            // Relative to the canvas's own box, so the page can place it with
            // absolute positioning inside the chart's wrapper.
            x: tooltip.caretX,
            y: tooltip.caretY,
            content,
        });

        // Silences the unused warning without pretending the chart is not part
        // of the contract: callers may need it later for canvas geometry.
        void chart;
    };
}
