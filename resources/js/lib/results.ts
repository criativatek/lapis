/**
 * How a result and its movement are READ, in one place.
 *
 * Resultados shows one period at a time and the Quadro Síntese shows the whole
 * year at once, and both must say the same thing about the same numbers. Two
 * copies of these rules is how one screen ends up calling a fall what the other
 * calls standing still.
 */

/**
 * Movement between the STANDALONE average of this period and the one before.
 * Null when either period has nothing comparable — an absence is not a fall.
 */
export type Evolution = {
    direction: 'up' | 'down' | 'flat';
    points: string;
    previous: string;
    current: string;
} | null;

/**
 * Trims the engine's six-decimal value to something a teacher reads. «—» for a
 * null, never 0, because a missing value is not a zero.
 */
export function pct(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return `${Number(value).toFixed(1)}%`;
}

/**
 * TENDÊNCIA, and never performance.
 *
 * The background says whether the student moved; a badge's colour says how they
 * are doing. A student who went 25% to 40% improved and is still failing, and
 * one who went 92% to 85% fell back and is still excellent — so the two must
 * never be drawn with the same ink (§9).
 */
export function trendClasses(evolution: Evolution): string {
    if (evolution === null || evolution.direction === 'flat') {
        return '';
    }

    return evolution.direction === 'up'
        ? 'bg-emerald-50 dark:bg-emerald-950/40'
        : 'bg-rose-50 dark:bg-rose-950/40';
}

export function trendArrow(evolution: Evolution): string {
    if (evolution === null || evolution.direction === 'flat') {
        return '';
    }

    return evolution.direction === 'up' ? '↑' : '↓';
}

/** «+20,0» — percentage POINTS, and «—» when there was nothing to compare. */
export function trendPoints(evolution: Evolution): string {
    if (evolution === null) {
        return '—';
    }

    const signed = Number(evolution.points) > 0 ? `+${evolution.points}` : evolution.points;

    return signed.replace('.', ',');
}

/** «Período anterior: 55,0% · atual: 75,0% · +20,0 p.p.» */
export function trendTitle(evolution: Evolution, accumulated: string | null): string | undefined {
    if (evolution === null) {
        return undefined;
    }

    const lines = [
        `Período anterior: ${pct(evolution.previous)}`,
        `Período atual: ${pct(evolution.current)}`,
        // Percentage POINTS: the difference between two percentages is not
        // itself a percentage.
        `Evolução: ${trendPoints(evolution)} p.p.`,
    ];

    if (accumulated !== null) {
        lines.push(`Média Ponderada Acumulada: ${pct(accumulated)}`);
    }

    return lines.join('\n');
}
