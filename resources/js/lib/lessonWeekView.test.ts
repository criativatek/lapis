import { describe, expect, it } from 'vitest';
import type { WeekLesson } from '@/lib/lessons';
import {
    DEFAULT_VIEW_STATE,
    matchesWeekFilters,
    parseWeekViewState,
    secondaryFilterCount,
    weekFacts,
    weekViewQuery,
    weekViewUrl,
} from '@/lib/lessonWeekView';

function lesson(overrides: Partial<WeekLesson> = {}): WeekLesson {
    return {
        ulid: 'a',
        starts_at: '2026-10-08T09:30:00+01:00',
        ends_at: '2026-10-08T10:20:00+01:00',
        school_class: { ulid: 'class-a', label: '7.º A', is_support_class: false },
        status: 'prepared',
        status_label: 'Preparada',
        outcome: null,
        outcome_label: null,
        has_summary: true,
        ...overrides,
    } as WeekLesson;
}

const now = new Date('2026-10-08T11:00:00+01:00');

describe('o estado de leitura vive no URL', () => {
    it('sem nada no URL, é a vista Semana com sumários completos e sem filtros', () => {
        expect(parseWeekViewState('/lessons?week=2026-10-05')).toEqual(DEFAULT_VIEW_STATE);
        expect(weekViewUrl(DEFAULT_VIEW_STATE, '2026-10-05')).toBe('/lessons?week=2026-10-05');
    });

    it('ida e volta sem perder nada', () => {
        const url = '/lessons?week=2026-10-05&view=turma&states=taught,preparation&attention=1&summary=sem&density=compacto&class=c1&group=2&range=4';
        const state = parseWeekViewState(url);

        expect(state).toMatchObject({ view: 'turma', states: ['taught', 'preparation'], attention: true, summary: 'sem', density: 'compacto', classUlid: 'c1', group: '2', range: '4' });
        expect(weekViewUrl(state, '2026-10-05')).toBe(url.replace('states=taught,preparation', 'states=taught%2Cpreparation'));
    });

    it('valores desconhecidos caem no valor por omissão em vez de partir', () => {
        expect(parseWeekViewState('/lessons?view=nada&states=inventado&summary=talvez&range=9')).toEqual(DEFAULT_VIEW_STATE);
    });

    it('os parâmetros da vista por turma só vão para o URL nessa vista', () => {
        expect(weekViewQuery({ ...DEFAULT_VIEW_STATE, classUlid: 'c1', range: '4' }, '2026-10-05')).toEqual({ week: '2026-10-05' });
    });
});

describe('filtros — «ou» dentro do grupo, «e» entre grupos', () => {
    it('turma, estado, atenção e sumário', () => {
        const state = { ...DEFAULT_VIEW_STATE, classes: ['class-a'], states: ['prepared' as const] };

        expect(matchesWeekFilters(lesson(), state, now)).toBe(true);
        expect(matchesWeekFilters(lesson({ school_class: { ulid: 'class-b', label: '8.º B', is_support_class: false } }), state, now)).toBe(false);
        expect(matchesWeekFilters(lesson(), { ...state, summary: 'sem' }, now)).toBe(false);
        expect(matchesWeekFilters(lesson(), { ...DEFAULT_VIEW_STATE, attention: true }, now)).toBe(true);
        expect(matchesWeekFilters(lesson({ starts_at: '2026-10-08T14:00:00+01:00', ends_at: '2026-10-08T14:50:00+01:00' }), { ...DEFAULT_VIEW_STATE, attention: true }, now)).toBe(false);
    });

    it('o resultado ganha à preparação, como no cartão', () => {
        const absent = lesson({ outcome: 'teacher_absent', outcome_label: 'Professor ausente' });

        expect(matchesWeekFilters(absent, { ...DEFAULT_VIEW_STATE, states: ['teacher_absent'] }, now)).toBe(true);
        expect(matchesWeekFilters(absent, { ...DEFAULT_VIEW_STATE, states: ['prepared'] }, now)).toBe(false);
    });

    it('conta os filtros secundários (os do painel recolhível)', () => {
        expect(secondaryFilterCount({ ...DEFAULT_VIEW_STATE, classes: ['x'], states: ['taught'], attention: true, summary: 'com' })).toBe(3);
    });
});

describe('weekFacts', () => {
    it('conta, não infere', () => {
        expect(
            weekFacts(
                [
                    lesson(),
                    lesson({ ulid: 'b', status: 'taught', outcome: 'taught', has_summary: false }),
                    lesson({ ulid: 'c', starts_at: '2026-10-07T09:30:00+01:00', ends_at: '2026-10-07T10:20:00+01:00', has_summary: false, status: 'preparation' }),
                ],
                now,
            ),
        ).toEqual({ total: 3, withSummary: 1, taughtWithoutSummary: 1, toConfirm: 2 });
    });
});
