/**
 * Trajetórias — os tipos que o servidor envia (`BuildClassTrends`, `BuildStudentYear.trends`) e as
 * palavras com que a página as lê.
 *
 * NADA SE CALCULA AQUI. Tendências, resultados abaixo do nível positivo persistentes, lacunas e momentos
 * deixados de fora chegam decididos; este ficheiro só lhes dá nome. As palavras descrevem números: não
 * diagnosticam, não preveem e não recomendam.
 */

export type TrendState = 'consistent_up' | 'consistent_down' | 'stable' | 'variable' | 'insufficient';
export type PersistentState = 'yes' | 'no' | 'no_recent' | 'no_value' | 'unknown' | 'insufficient';

export type TrendStep = { from: string; to: string; direction: 'up' | 'down' | 'flat'; points: string; previous: string; current: string };

export type Trend = {
    state: TrendState;
    with_value: number;
    steps: TrendStep[];
    counts: { up: number; flat: number; down: number };
    gaps: Array<{ label: string; reason: 'no_value' | 'left_out' }>;
};

export type Persistent = {
    state: PersistentState;
    run: number;
    points: Array<{ label: string; value: string; band: { code: string | null; label: string | null; is_negative: boolean | null } }>;
    stopped_by: 'start' | 'positive' | 'no_value' | 'left_out' | 'unknown_band';
};

export type Reading = {
    values: Array<string | null>;
    negative: Array<boolean | null>;
    trend: Trend;
    persistent: Persistent;
};

export type SeriesPoint = {
    key: string;
    label: string;
    kind: 'period' | 'kept_moment';
    basis_kind: 'live' | 'frozen';
    basis_label?: string;
    comparable: boolean;
};

export type LeftOut = { key: string; label: string; reasons: Array<{ code: string; text: string }> };

export type Availability = { comparable_points: number; trends: boolean; persistence: boolean; reason: string | null };

export type TrendCounts = {
    of: number;
    with_latest: number;
    trend: Record<TrendState, number>;
    persistent: Record<PersistentState, number>;
};

export type ClassReading = {
    cohort: number;
    means: Array<string | null>;
    everyone: Array<{ students: number; mean: string | null }>;
    trend: Trend;
};

export type ClassTrends = {
    state: 'ready' | 'unavailable';
    reason: string | null;
    availability: Availability;
    reference: { key: string; label: string } | null;
    points: SeriesPoint[];
    left_out: LeftOut[];
    class: { global: ClassReading | null; domains: Array<ClassReading & { ulid: string; name: string }> };
    counts: { students: number; global: TrendCounts | null; domains: Array<TrendCounts & { ulid: string; name: string }> };
    students: Array<{
        enrollment_ulid: string;
        name: string;
        class_number: number | null;
        global: Reading;
        domains: Record<string, Reading>;
    }>;
    cautions: Array<{ code: string; text: string }>;
    explanations: Array<{ key: string; title: string; text: string }>;
};

export type StudentTrends = {
    availability: Availability;
    reference: { key: string; label: string };
    points: SeriesPoint[];
    left_out: LeftOut[];
    result: Reading;
    domains: Array<Reading & { ulid: string; name: string }>;
};

/** The four names the trends have, and the sentence that says what they rest on. */
export const TREND_WORD: Record<TrendState, string> = {
    consistent_up: 'Progressão consistente',
    consistent_down: 'Regressão consistente',
    stable: 'Estável',
    variable: 'Evolução variável',
    insufficient: 'Sem momentos suficientes',
};

export const TREND_ARROW: Record<TrendState, string> = {
    consistent_up: '↑',
    consistent_down: '↓',
    stable: '→',
    variable: '↕',
    insufficient: '–',
};

export const TREND_CLASS: Record<TrendState, string> = {
    consistent_up: 'text-emerald-700 dark:text-emerald-400',
    consistent_down: 'text-rose-700 dark:text-rose-400',
    stable: 'text-muted-foreground',
    variable: 'text-amber-700 dark:text-amber-500',
    insufficient: 'text-muted-foreground',
};

/** «Subiu em 3 de 3 passos» — the evidence for a trend, in numbers. */
export function stepsSentence(trend: Trend): string {
    const total = trend.steps.length;

    if (total === 0) {
        return trend.with_value > 0 ? `${trend.with_value} momento${trend.with_value === 1 ? '' : 's'} com valor: uma tendência precisa de 3` : 'Sem valores';
    }

    const parts: string[] = [];

    if (trend.counts.up) {
        parts.push(`subiu em ${trend.counts.up}`);
    }

    if (trend.counts.flat) {
        parts.push(`ficou igual em ${trend.counts.flat}`);
    }

    if (trend.counts.down) {
        parts.push(`desceu em ${trend.counts.down}`);
    }

    return `${parts.join(', ')} de ${total} passos`.replace(/^./, (letter) => letter.toUpperCase());
}

export function gapSentence(trend: Trend): string | null {
    if (trend.gaps.length === 0) {
        return null;
    }

    const noValue = trend.gaps.filter((gap) => gap.reason === 'no_value').map((gap) => gap.label);
    const leftOut = trend.gaps.filter((gap) => gap.reason === 'left_out').map((gap) => gap.label);
    const parts: string[] = [];

    if (noValue.length) {
        parts.push(`sem valor em ${noValue.join(', ')}`);
    }

    if (leftOut.length) {
        parts.push(`${leftOut.join(', ')} fora da linha por critérios ou escala diferentes`);
    }

    return `Entre o primeiro e o último valor: ${parts.join('; ')}. A tendência assenta só nos momentos com valor.`;
}

/** «Abaixo do nível positivo em 2 momentos seguidos, incluindo o mais recente». */
export function persistentSentence(persistent: Persistent): string | null {
    switch (persistent.state) {
        case 'yes':
            return `Abaixo do nível positivo em ${persistent.run} momentos seguidos, incluindo o mais recente.`;
        case 'no_recent':
            return 'Sem valor no momento mais recente: não se pode dizer que continua abaixo do nível positivo.';
        case 'unknown':
            return 'Sem linha entre positivo e negativo no momento mais recente: não se sabe se está abaixo do nível positivo.';
        default:
            return null;
    }
}

/** Why a run stopped, when it stopped at a hole or at a change of rule. */
export function stoppedSentence(persistent: Persistent): string | null {
    if (persistent.state !== 'yes' && persistent.run !== 1) {
        return null;
    }

    switch (persistent.stopped_by) {
        case 'no_value':
            return 'A sequência acaba num momento sem valor.';
        case 'left_out':
            return 'A sequência acaba num momento com critérios ou escala diferentes.';
        case 'unknown_band':
            return 'A sequência acaba num momento em que a escala não permite dizer se estava abaixo do nível positivo.';
        default:
            return null;
    }
}
