/**
 * A comparação entre dois momentos — os tipos que o servidor envia e as palavras que
 * a página usa para os ler.
 *
 * NADA SE CALCULA AQUI. Cada número, cada contagem e cada mudança de nível chega no
 * payload de `BuildAssessmentComparison`; este ficheiro só lhes dá nome e formato.
 * Uma segunda conta no browser seria uma segunda opinião sobre um número que já tem uma.
 */

export type Level = { code: string | null; label: string | null; sequence: number | null; is_negative: boolean | null };

export type Basis = { kind: 'live' | 'frozen'; label: string; as_of: string | null };

export type PointInfo = {
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

export type Movement = { direction: 'up' | 'down' | 'flat'; points: string; previous: string; current: string } | null;

export type Counts = { up: number; flat: number; down: number; compared: number } | null;

export type StudentMovement = {
    state: 'up' | 'down' | 'flat' | 'unavailable';
    points: string | null;
    reason: 'missing_from' | 'missing_to' | 'missing_both' | 'not_comparable' | null;
};

export type LevelChange = {
    state: 'up' | 'down' | 'same' | 'unavailable';
    from: Level | null;
    to: Level | null;
    crossed_line: 'to_positive' | 'to_negative' | null;
    reason: string | null;
    from_value?: string | null;
    to_value?: string | null;
};

export type Figure = { value: string | null; band: Level | null; coverage_warning: boolean } | null;

export type ComparedStudent = {
    enrollment_ulid: string;
    name: string;
    class_number: number | null;
    from: Figure;
    to: Figure;
    movement: StudentMovement;
    result_level: LevelChange;
    assigned: { from: Level | null; to: Level | null; from_value: string | null; to_value: string | null; change: LevelChange };
    domains: Record<string, { from: string | null; to: string | null; movement: StudentMovement }>;
};

export type MeanBlock = { students: number; mean: string | null; partial_coverage?: number };

export type PairedBlock = { students: number; from_mean: string | null; to_mean: string | null; change: Movement };

export type DomainBlock = {
    ulid: string;
    name: string;
    weight_percent: string | null;
    moments: { from: MeanBlock; to: MeanBlock };
    paired: PairedBlock;
    counts: Counts;
};

export type LevelSummary = {
    counts: { up: number; same: number; down: number; compared: number; not_compared: number };
    crossed: { to_positive: number; to_negative: number };
    distribution: Array<{ code: string; label: string; sequence: number; is_negative: boolean; from: number; to: number }>;
};

export type Comparison = {
    state: 'ready' | 'unavailable';
    reason: string | null;
    scale: { name: string; kind: string; levels: Array<{ code: string; label: string; sequence: number; is_negative: boolean }> } | null;
    options: PointInfo[];
    selection: { from: string | null; to: string | null; defaulted: boolean; swapped: boolean };
    from: PointInfo | null;
    to: PointInfo | null;
    comparability: { percentages: boolean; levels: boolean; reasons: Array<{ code: string; text: string }> } | null;
    basis_note: string | null;
    cautions: Array<{ code: string; text: string }>;
    notices: Array<{ code: string; text: string; moment: string }>;
    class: {
        moments: { from: MeanBlock; to: MeanBlock };
        paired: PairedBlock;
        counts: Counts;
        not_compared: { missing_from: number; missing_to: number; missing_both: number };
    } | null;
    domains: DomainBlock[];
    levels: { comparable: boolean; reasons: Array<{ code: string; text: string }>; result: LevelSummary | null; assigned: LevelSummary | null } | null;
    students: ComparedStudent[];
    explanations: Array<{ key: string; title: string; text: string }>;
};

/** «62,0%» — one decimal, comma; «—» for an absence, never a zero. */
export function percent(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return `${Number(value).toFixed(1).replace('.', ',')}%`;
}

/** «+8,5 p.p.» — percentage POINTS: the difference of two percentages is not a percentage. */
export function points(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const number = Number(value);
    const text = number.toFixed(1).replace('.', ',');

    return `${number > 0 ? '+' : ''}${text} p.p.`;
}

export function movementWord(state: StudentMovement['state']): string {
    return { up: 'Progrediu', down: 'Regrediu', flat: 'Manteve-se', unavailable: 'Sem comparação' }[state];
}

/** Why a student has no movement — in words a teacher would say. */
export function reasonWord(reason: StudentMovement['reason']): string {
    switch (reason) {
        case 'missing_from':
            return 'Sem resultado no primeiro momento.';
        case 'missing_to':
            return 'Sem resultado no segundo momento.';
        case 'missing_both':
            return 'Sem resultado em nenhum dos momentos.';
        case 'not_comparable':
            return 'Os critérios mudaram entre os dois momentos.';
        default:
            return '';
    }
}

/** Why a level change is not given. */
export function levelReasonWord(reason: string | null): string {
    switch (reason) {
        case 'missing':
            return 'Sem nível num dos momentos.';
        case 'not_comparable':
            return 'A escala não é demonstravelmente a mesma nos dois momentos.';
        case 'no_sequence':
            return 'O nível não tem lugar na escala.';
        case 'decided_on_another_scale':
            return 'Decidido noutra escala.';
        default:
            return '';
    }
}

/** «Suficiente → Bom», or the sentence for the one change a school cares about. */
export function levelChangeText(change: LevelChange): string {
    if (change.state === 'unavailable') {
        return levelReasonWord(change.reason) || '—';
    }

    const from = change.from?.label ?? change.from?.code ?? '—';
    const to = change.to?.label ?? change.to?.code ?? '—';

    if (change.state === 'same') {
        return `Manteve «${to}»`;
    }

    const line = change.crossed_line === 'to_positive' ? ' — passou a nível positivo' : change.crossed_line === 'to_negative' ? ' — passou a nível negativo' : '';

    return `${from} → ${to}${line}`;
}

export function studentsCount(count: number): string {
    return count === 1 ? '1 aluno' : `${count} alunos`;
}
