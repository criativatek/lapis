/**
 * How a chart is dressed so that it belongs to LÁPIS.
 *
 * Three visual languages, kept apart on purpose (§34):
 *
 *   A. DESEMPENHO — the qualitative scale's own tones. Green/blue/amber/red,
 *      placed by qualitativeToneFor(), which reads a band's structure and never
 *      its label.
 *   B. EVOLUÇÃO — emerald for movement up, rose for movement down, neutral for
 *      standing still and a fainter neutral for nothing to compare. The same
 *      inks trendClasses() already uses on Resultados.
 *   C. ESTRUTURA — the page's own neutral tokens: axes, grid, labels.
 *
 * Mixing them is how a chart ends up telling a teacher that a student who fell
 * back is doing badly, which is a different claim about a different thing.
 *
 * The neutral chrome is READ FROM THE DOCUMENT rather than hard-coded, because
 * the tokens change with the theme and a canvas cannot inherit a CSS variable.
 */

import type { QualitativeTone } from '@/lib/qualitativeTone';

/**
 * Performance tones, matched to the Tailwind families qualitativeToneClasses
 * paints badges with. Two shades each: the fill, and the stronger border/hover
 * that gives a bar its edge.
 */
export const TONE_COLOURS: Record<QualitativeTone, { fill: string; border: string }> = {
    green: { fill: 'rgba(16, 185, 129, 0.75)', border: 'rgb(5, 150, 105)' },
    blue: { fill: 'rgba(59, 130, 246, 0.75)', border: 'rgb(37, 99, 235)' },
    amber: { fill: 'rgba(245, 158, 11, 0.75)', border: 'rgb(217, 119, 6)' },
    red: { fill: 'rgba(244, 63, 94, 0.75)', border: 'rgb(225, 29, 72)' },
    neutral: { fill: 'rgba(148, 163, 184, 0.6)', border: 'rgb(100, 116, 139)' },
};

/** Movement, never performance. */
export const TREND_COLOURS = {
    up: { fill: 'rgba(16, 185, 129, 0.75)', border: 'rgb(5, 150, 105)' },
    down: { fill: 'rgba(244, 63, 94, 0.75)', border: 'rgb(225, 29, 72)' },
    flat: { fill: 'rgba(148, 163, 184, 0.65)', border: 'rgb(100, 116, 139)' },
    none: { fill: 'rgba(148, 163, 184, 0.28)', border: 'rgb(148, 163, 184)' },
};

/**
 * Distinct hues for the per-domain lines.
 *
 * STRUCTURE, not performance: these say «this line is Leitura», nothing about
 * how Leitura is going. Deliberately not the tone palette, so a domain drawn in
 * red is never mistaken for a failing one.
 */
export const SERIES_COLOURS: string[] = [
    'rgb(37, 99, 235)',
    'rgb(139, 92, 246)',
    'rgb(6, 148, 162)',
    'rgb(217, 119, 6)',
    'rgb(219, 39, 119)',
    'rgb(22, 163, 74)',
    'rgb(100, 116, 139)',
    'rgb(202, 138, 4)',
];

export function seriesColour(index: number): string {
    return SERIES_COLOURS[index % SERIES_COLOURS.length];
}

/**
 * The neutral chrome, read from the live document so the charts follow the
 * theme without being told about it.
 *
 * Falls back to values that are legible on either theme when there is no
 * document to read — a server render, or a test environment.
 */
export function chromeColours(): { text: string; muted: string; grid: string; surface: string } {
    if (typeof window === 'undefined' || typeof getComputedStyle !== 'function') {
        return { text: '#0a0a0a', muted: '#737373', grid: 'rgba(120,120,120,0.2)', surface: '#ffffff' };
    }

    const styles = getComputedStyle(document.documentElement);
    const read = (token: string, fallback: string): string => styles.getPropertyValue(token).trim() || fallback;

    return {
        text: read('--foreground', '#0a0a0a'),
        muted: read('--muted-foreground', '#737373'),
        // The border token at low opacity would need colour maths on an hsl()
        // string; a neutral wash reads correctly on both themes instead.
        grid: isDark() ? 'rgba(255,255,255,0.10)' : 'rgba(0,0,0,0.08)',
        surface: read('--card', '#ffffff'),
    };
}

export function isDark(): boolean {
    return typeof document !== 'undefined' && document.documentElement.classList.contains('dark');
}

/**
 * Whether this reader asked not to be animated.
 *
 * Respected by every chart on the page: the bars appear at their final height
 * and the lines are simply drawn (§19).
 */
export function prefersReducedMotion(): boolean {
    return typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** 600ms of ease-out, or nothing at all when motion is not wanted. */
export function animationOptions(): { duration: number; easing: 'easeOutQuart' } {
    return { duration: prefersReducedMotion() ? 0 : 600, easing: 'easeOutQuart' };
}

/**
 * «72,4%» — the same reading as everywhere else, with the decimal comma
 * pt-PT writes. Null is «—», never 0 (§37, §38).
 */
export function formatPercent(value: string | number | null): string {
    if (value === null || value === '') {
        return '—';
    }

    return `${Number(value).toFixed(1).replace('.', ',')}%`;
}

/** «+4,8» / «−3,1» — percentage POINTS, signed, never a percentage. */
export function formatPoints(value: string | number | null): string {
    if (value === null || value === '') {
        return '—';
    }

    const number = Number(value);
    const signed = number > 0 ? `+${number.toFixed(1)}` : number.toFixed(1);

    return signed.replace('.', ',').replace('-', '−');
}

/** «25,0%» for a share of a group, distinct from a result that is a percentage. */
export function formatShare(value: string | number | null): string {
    return value === null || value === '' ? '—' : `${Number(value).toFixed(1).replace('.', ',')}%`;
}
