import type { UsageFilters } from '@/types/usage';

/** «2026-10-11» (ou «2026-10-11 14:05») → «11/10/2026». Sem Date: não há fusos a enganar. */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.slice(0, 10).split('-');

    return `${day}/${month}/${year}`;
}

/** «11/10» — para intervalos de semana. */
export function formatDay(value: string): string {
    const [, month, day] = value.slice(0, 10).split('-');

    return `${day}/${month}`;
}

export function formatRange(start: string, end: string): string {
    return `${formatDay(start)}–${formatDay(end)}`;
}

/** «14:05 de 11/10» a partir de «2026-10-11 14:05». */
export function formatTimeOfDate(value: string): string {
    return `${value.slice(11, 16)} de ${formatDay(value)}`;
}

export function formatNumber(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : value.toLocaleString('pt-PT');
}

/** «4 de 9 · 44%» — a percentagem só existe com denominador. */
export function ofTotal(done: number, of: number): string {
    if (of <= 0) {
        return formatNumber(done);
    }

    return `${formatNumber(done)} de ${formatNumber(of)} · ${Math.round((done / of) * 100)}%`;
}

type Query = Record<string, string>;

/** Os filtros comuns às quatro páginas, tal como viajam na query. */
export function commonQuery(filters: UsageFilters): Query {
    const query: Query = { period: filters.period };

    if (filters.period === 'custom') {
        query.from = filters.from;
        query.to = filters.to;
    }

    if (filters.plan) {
        query.plan = filters.plan;
    }

    if (filters.organization) {
        query.organization = filters.organization;
    }

    if (filters.segment !== 'main') {
        query.segment = filters.segment;
    }

    return query;
}

export function withQuery(path: string, query: Record<string, string | null | undefined>): string {
    const params = new URLSearchParams();

    Object.entries(query).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            params.set(key, value);
        }
    });

    const text = params.toString();

    return text === '' ? path : `${path}?${text}`;
}

/** O que cada estado de cobertura diz, em palavras. */
export function statusLabel(status: string, unit: 'week' | 'month' = 'week'): string {
    const noun = unit === 'week' ? 'Semana' : 'Mês';

    switch (status) {
        case 'complete':
            return 'Completa';
        case 'in_progress':
            return `${noun} em curso`;
        case 'partial_period':
        case 'partial_coverage':
            return `${noun} parcial`;
        case 'no_coverage':
            return 'Sem recolha';
        default:
            return status;
    }
}

/** «—» para null: um período sem recolha nunca é um zero. */
export function dash(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : formatNumber(value);
}

/** Filtros comuns + os da lista de acompanhamento: o que «Voltar» precisa para repor a lista. */
export function listQuery(filters: UsageFilters): Query {
    const query = commonQuery(filters);

    if (filters.q) {
        query.q = filters.q;
    }

    if (filters.signal) {
        query.signal = filters.signal;
    }

    if (filters.sort) {
        query.sort = filters.sort;
    }

    return query;
}

export const NO_ACTIVITY = 'Sem atividade observada desde o início da recolha';
