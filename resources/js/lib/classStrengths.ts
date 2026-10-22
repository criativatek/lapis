/**
 * Pontos fortes e dificuldades de um momento — os tipos que o servidor envia
 * (`BuildClassStrengths`) e as palavras com que a página os lê.
 *
 * NADA SE CALCULA AQUI: médias, contagens, destaques e a linha entre positivo e negativo chegam
 * decididos. Este ficheiro só lhes dá formato — e a regra de dizer sempre «k de n».
 */
import type { Basis, Level } from '@/lib/assessmentComparison';

export type StrengthsPoint = {
    key: string;
    kind: 'period' | 'kept_moment';
    label: string;
    basis: Basis;
    ordinal: number;
    students_total: number;
    students_with_result: number;
    partial_coverage: number;
    few_students: boolean;
};

export type CoverageState = 'none' | 'few' | 'incomplete' | 'complete';

export type StrengthsRow = {
    ulid: string | null;
    name: string | null;
    students: { with_value: number; of: number };
    mean: string | null;
    below_positive: { count: number; of: number; share: string } | null;
    coverage: { state: CoverageState; partial: number };
};

export type Highlight = {
    key: string;
    text: string;
    evidence: { domain: string | null; count: number; of: number };
};

export type BelowStudent = {
    enrollment_ulid: string;
    name: string;
    class_number: number | null;
    domains_with_value: number;
    domains: Array<{ ulid: string; name: string; value: string; band: Level }>;
};

export type Brief = { ulid: string; name: string; mean: string; students: number; few_students: boolean } | null;

export type ClassStrengths = {
    state: 'ready' | 'unavailable';
    reason: string | null;
    options: StrengthsPoint[];
    selection: { moment: string | null; defaulted: boolean };
    moment: StrengthsPoint | null;
    basis_note: string | null;
    cautions: Array<{ code: string; text: string }>;
    scale: { name: string | null; levels: Array<{ code: string; label: string; sequence: number; is_negative: boolean }>; same_as_current: boolean; known: boolean; negative_levels: string[] } | null;
    negative_line: { known: boolean; reason: string | null; text: string | null; negative_levels: string[] };
    result: StrengthsRow | null;
    domains: StrengthsRow[];
    ranking: { state: 'ok' | 'all_equal' | 'insufficient'; highest_mean: string | null; lowest_mean: string | null; highest: Brief; highest_tie: string[]; lowest: Brief; lowest_tie: string[] };
    students_below: { known: boolean; of: number; none: number; one: number; two_or_more: number; students: BelowStudent[] };
    highlights: { strengths: Highlight[]; attention: Highlight[] };
    explanations: Array<{ key: string; title: string; text: string }>;
};

/** «8 de 27 alunos» — a count never travels without the total it rests on. */
export function countOf(count: number, of: number): string {
    return `${count} de ${of} ${of === 1 ? 'aluno' : 'alunos'}`;
}

export const COVERAGE_WORD: Record<CoverageState, string> = {
    none: 'Sem resultados',
    few: 'Poucos alunos',
    incomplete: 'Incompleta',
    complete: 'Completa',
};
