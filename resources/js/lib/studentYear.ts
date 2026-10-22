/**
 * «Ao longo do ano» — os tipos que o servidor envia (BuildStudentYear) e as palavras com
 * que a secção os lê.
 *
 * NADA SE CALCULA AQUI: variações, mudanças de nível e comparabilidade chegam decididas; este
 * ficheiro só lhes dá nome. As palavras são as da própria página («Subida», «Descida», «Estável»).
 */
import type { Basis, Figure, Level, LevelChange, StudentMovement } from '@/lib/assessmentComparison';

export type YearPoint = {
    key: string;
    kind: 'period' | 'kept_moment';
    label: string;
    basis: Basis;
    ordinal: number;
    partial_coverage: boolean;
};

export type YearDomain = {
    ulid: string;
    name: string;
    weight_percent: string | null;
    from: string | null;
    to: string | null;
    movement: StudentMovement;
    from_band: Level | null;
    to_band: Level | null;
    level: LevelChange;
    coverage_warning: [boolean, boolean];
};

export type YearSeries = {
    enough: boolean;
    points: Array<{ key: string; kind: 'period' | 'kept_moment'; label: string; basis_kind: 'live' | 'frozen' }>;
    result: Array<string | null>;
    domains: Array<{ ulid: string; name: string; values: Array<string | null> }>;
    left_out: number;
    note: string | null;
};

export type YearReading = {
    state: 'ready' | 'unavailable';
    reason: string | null;
    scale: { name: string; kind: string; levels: Array<{ code: string; label: string; sequence: number; is_negative: boolean }> } | null;
    options: YearPoint[];
    selection: { from: string | null; to: string | null; defaulted: boolean; swapped: boolean };
    from: YearPoint | null;
    to: YearPoint | null;
    comparability: { percentages: boolean; levels: boolean; reasons: Array<{ code: string; text: string }> } | null;
    basis_note: string | null;
    cautions: Array<{ code: string; text: string }>;
    notices: Array<{ code: string; text: string; moment: string }>;
    result: { from: Figure; to: Figure; movement: StudentMovement; level: LevelChange } | null;
    assigned: { from: Level | null; to: Level | null; from_value: string | null; to_value: string | null; change: LevelChange } | null;
    domains: YearDomain[];
    series: YearSeries;
    explanations: Array<{ key: string; title: string; text: string }>;
};

/** The words this page already uses for a movement: «Subida», «Descida», «Estável». */
export function trendWord(state: StudentMovement['state']): string {
    return { up: 'Subida', down: 'Descida', flat: 'Estável', unavailable: 'Sem comparação' }[state];
}

export function trendArrow(state: StudentMovement['state']): string {
    return state === 'up' ? '↑' : state === 'down' ? '↓' : state === 'flat' ? '→' : '–';
}

/** A single stable colour per series, by position — a legend names them, the table repeats the numbers. */
const PALETTE = ['#0d9488', '#d97706', '#db2777', '#0284c7', '#7c3aed', '#65a30d', '#dc2626', '#0891b2'];

export function seriesColour(index: number): string {
    return PALETTE[index % PALETTE.length];
}
