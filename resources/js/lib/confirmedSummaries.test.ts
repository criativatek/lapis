import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    consumeLessonsStale,
    hasPendingConfirmations,
    hydrateConfirmedSummaries,
    markLessonsStale,
    pruneCaughtUp,
    recordConfirmedSummary,
    resetConfirmedSummaries,
    withConfirmedSummary,
    withConfirmedSummaries,
} from './confirmedSummaries';

const lesson = (overrides: Partial<{ summary: string | null; has_summary: boolean; summary_version: number }> = {}) => ({
    ulid: 'lesson-a',
    summary: 'Texto antigo.' as string | null,
    has_summary: true,
    summary_version: 3,
    ...overrides,
});

beforeEach(() => resetConfirmedSummaries());
afterEach(() => vi.restoreAllMocks());

describe('gravações confirmadas', () => {
    it('a versão maior ganha; menor ou igual é ignorada', () => {
        recordConfirmedSummary('lesson-a', 'Quatro.', 4);
        recordConfirmedSummary('lesson-a', 'Três.', 3);
        recordConfirmedSummary('lesson-a', 'Outra quatro.', 4);

        expect(withConfirmedSummary(lesson()).summary).toBe('Quatro.');

        recordConfirmedSummary('lesson-a', 'Cinco.', 5);

        expect(withConfirmedSummary(lesson())).toMatchObject({ summary: 'Cinco.', summary_version: 5, has_summary: true });
    });

    it('sobrepõe só quando as props estão atrás, e nunca toca nas outras aulas', () => {
        recordConfirmedSummary('lesson-a', 'Novo.', 4);
        const other = { ...lesson(), ulid: 'lesson-b' };

        expect(withConfirmedSummaries([lesson(), other])).toEqual([
            { ...lesson(), summary: 'Novo.', summary_version: 4 },
            other,
        ]);
        expect(withConfirmedSummary(lesson({ summary_version: 4, summary: 'Do servidor.' })).summary).toBe('Do servidor.');
        expect(hasPendingConfirmations([lesson()])).toBe(true);
        expect(hasPendingConfirmations([lesson({ summary_version: 4 })])).toBe(false);
    });

    it('um sumário limpo confirmado mostra-se sem texto', () => {
        recordConfirmedSummary('lesson-a', '', 4);

        expect(withConfirmedSummary(lesson())).toMatchObject({ summary: null, has_summary: false, summary_version: 4 });
    });

    it('descarta a entrada quando as props a alcançam', () => {
        recordConfirmedSummary('lesson-a', 'Novo.', 4);

        pruneCaughtUp([lesson({ summary_version: 4 })]);

        expect(withConfirmedSummary(lesson())).toEqual(lesson());
        expect(window.sessionStorage.getItem('lapis.lessons.confirmedSummaries')).toBe('{}');
    });

    it('sobrevive a uma página nova através do sessionStorage', () => {
        recordConfirmedSummary('lesson-a', 'Novo.', 4);
        const stored = window.sessionStorage.getItem('lapis.lessons.confirmedSummaries');
        resetConfirmedSummaries();
        window.sessionStorage.setItem('lapis.lessons.confirmedSummaries', stored ?? '');

        hydrateConfirmedSummaries();

        expect(withConfirmedSummary(lesson()).summary).toBe('Novo.');
    });

    it('tolera um sessionStorage que lança, ou JSON estragado', () => {
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('quota');
        });
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('negado');
        });

        expect(() => recordConfirmedSummary('lesson-a', 'Novo.', 4)).not.toThrow();
        expect(() => hydrateConfirmedSummaries()).not.toThrow();
        expect(withConfirmedSummary(lesson()).summary).toBe('Novo.');

        markLessonsStale();
        expect(consumeLessonsStale()).toBe(true);

        vi.restoreAllMocks();
        window.sessionStorage.setItem('lapis.lessons.confirmedSummaries', '{não é json');

        expect(() => hydrateConfirmedSummaries()).not.toThrow();
    });

    it('o sinal «aulas mudaram» consome-se uma só vez', () => {
        expect(consumeLessonsStale()).toBe(false);

        markLessonsStale();

        expect(consumeLessonsStale()).toBe(true);
        expect(consumeLessonsStale()).toBe(false);
    });
});
