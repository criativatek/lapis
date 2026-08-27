/**
 * Maps a scale band to a semantic colour tone by its STRUCTURE — is_negative
 * and sequence — never by comparing its label text. Lapispro supports custom
 * and future translated scales; a mapper keyed on "Bom" or "Muito Bom" would
 * silently lose its colour the day either changes. is_negative is an
 * explicit failing/passing flag already carried by every scale level;
 * sequence is its rank within the scale. Together they place a band without
 * ever reading what it's called.
 *
 * Reusable anywhere a scale band needs a colour: Avaliações, Resultados,
 * Evolução do Aluno, Análise da Turma, Relatórios — not just the grading
 * grid this was built for.
 */

export type ToneableScaleBand = {
    sequence: number;
    is_negative: boolean;
};

export type QualitativeTone = 'green' | 'blue' | 'amber' | 'red' | 'neutral';

/**
 * is_negative alone settles "failing" — always red, regardless of how many
 * failing bands a scale has or how they'd otherwise rank. Among the
 * non-negative bands of the SAME scale, relative sequence position splits
 * them into up to three tiers (amber the lowest passing, blue the middle,
 * green the highest), so a 3-level "Suficiente/Bom/Muito Bom" scale maps
 * exactly onto amber/blue/green, and scales with fewer or more passing
 * bands degrade gracefully rather than error.
 *
 * @param band the band to tone
 * @param allBandsInScale every band of the SAME scale (band's own scale, not a mix)
 */
export function qualitativeToneFor(band: ToneableScaleBand, allBandsInScale: ToneableScaleBand[]): QualitativeTone {
    if (band.is_negative) {
        return 'red';
    }

    const passingBands = allBandsInScale
        .filter((candidate) => !candidate.is_negative)
        .sort((a, b) => a.sequence - b.sequence);

    const rank = passingBands.findIndex((candidate) => candidate.sequence === band.sequence);

    if (rank === -1) {
        return 'neutral';
    }

    const fraction = passingBands.length <= 1 ? 1 : rank / (passingBands.length - 1);

    if (fraction >= 2 / 3) {
        return 'green';
    }

    if (fraction >= 1 / 3) {
        return 'blue';
    }

    return 'amber';
}

/**
 * Background/text pairs for each tone, legible in both light and dark mode.
 * Deliberately muted (100-level backgrounds, 800/900 text in light; inverted
 * in dark) rather than saturated — a badge, not an alert. Colour is
 * reinforcement only: every caller still renders the band's own text label
 * alongside this class, never a bare coloured dot.
 */
export const qualitativeToneClasses: Record<QualitativeTone, string> = {
    green: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    blue: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    amber: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-300',
    red: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    neutral: 'bg-muted text-muted-foreground',
};
