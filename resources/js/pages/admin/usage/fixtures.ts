import type { UsageCommonProps, UsageOnline, UsageSignal } from '@/types/usage';

export function common(overrides: Partial<UsageCommonProps> = {}): UsageCommonProps {
    return {
        filters: {
            period: '30',
            from: '2026-09-12',
            to: '2026-10-11',
            days: 30,
            plan: null,
            organization: null,
            segment: 'real',
            adjusted_to_retention: false,
            q: '',
            signal: null,
            sort: null,
            age: 'all',
        },
        options: {
            plans: [{ key: 'pro', label: 'Pro' }],
            organizations: [{ ulid: 'o1', name: 'Escola Azul', type: 'institutional', classification: 'test' }],
            segments: [
                { key: 'real', label: 'Utilização real', description: 'Contas classificadas como utilização real.' },
                { key: 'test', label: 'Teste e demonstração', description: 'Dados fictícios, QA, ensaios.' },
                { key: 'internal', label: 'Interno', description: 'Contas internas e pessoal interno.' },
                { key: 'unclassified', label: 'Por classificar', description: 'Ninguém classificou ainda.' },
            ],
            signal_options: [{ key: 'no_return', label: 'Sem regresso' }],
        },
        coverage: {
            collection_started_at: '2026-09-20 10:00',
            start_day: '2026-09-20',
            retention_cutoff: '2025-10-11',
            aggregated_at: '2026-10-11 03:15',
            observed_from: '2026-09-20',
            observed_to: '2026-10-11',
            period_status: 'partial',
            notes: ['A recolha começou a 20/09/2026.'],
        },
        definitions: [{ term: 'Utilizador ativo', text: 'Teve pelo menos uma interação elegível.' }],
        ...overrides,
    };
}

export const signal: UsageSignal = {
    key: 'no_return',
    label: 'Sem regresso',
    reason: 'Não voltou nas duas semanas seguintes à adesão.',
    window_from: '2026-09-28',
    window_to: '2026-10-11',
};

export function online(overrides: Partial<UsageOnline> = {}): UsageOnline {
    return {
        as_of: '10:00',
        window_minutes: 15,
        session_minutes: 120,
        active_count: 1,
        people: [
            { ulid: 'u1', name: 'Ana Silva', email: 'ana@exemplo.pt', internal: false, minutes_ago: 3, active: true },
            { ulid: null, name: 'Rui Costa', email: 'rui@exemplo.pt', internal: true, minutes_ago: 47, active: false },
        ],
        ...overrides,
    };
}
