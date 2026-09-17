import { describe, expect, it } from 'vitest';
import { isQuickClosable, lessonDisplayState, lessonQuickCloseState } from '@/lib/lessons';
import type { LessonStateSource, LessonTimingSource } from '@/lib/lessons';

/**
 * 0.146.1 — uma aula com resultado registado está fechada: o cartão nunca
 * mostra «Preparada» ao lado de «Professor ausente».
 */
function source(overrides: Partial<LessonStateSource> = {}): LessonStateSource {
    return { status: 'prepared', status_label: 'Preparada', outcome: null, outcome_label: null, ...overrides };
}

describe('lessonDisplayState', () => {
    it('sem resultado mostra a preparação', () => {
        expect(lessonDisplayState(source())).toEqual({ value: 'prepared', label: 'Preparada' });
        expect(lessonDisplayState(source({ status: 'preparation', status_label: 'Por preparar' }))).toEqual({
            value: 'preparation',
            label: 'Por preparar',
        });
    });

    it('professor ausente substitui «Preparada»', () => {
        expect(
            lessonDisplayState(source({ outcome: 'teacher_absent', outcome_label: 'Professor ausente' })),
        ).toEqual({ value: 'teacher_absent', label: 'Professor ausente' });
    });

    it('turma em outras atividades letivas substitui «Por preparar»', () => {
        expect(
            lessonDisplayState(
                source({
                    status: 'preparation',
                    status_label: 'Por preparar',
                    outcome: 'class_external_activity',
                    outcome_label: 'Turma em outras atividades letivas',
                }),
            ),
        ).toEqual({ value: 'class_external_activity', label: 'Turma em outras atividades letivas' });
    });

    it('lecionada continua a vir do estado da aula', () => {
        expect(
            lessonDisplayState(source({ status: 'taught', status_label: 'Lecionada', outcome: 'taught', outcome_label: 'Lecionada' })),
        ).toEqual({ value: 'taught', label: 'Lecionada' });
    });
});

/**
 * 0.147.0 — fecho rápido. O relógio entra como argumento: nenhum destes casos
 * depende do dia em que a suite corre.
 */
describe('lessonQuickCloseState', () => {
    const now = new Date('2026-10-08T11:00:00+01:00');

    function timing(overrides: Partial<LessonTimingSource> = {}): LessonTimingSource {
        return {
            status: 'prepared',
            outcome: null,
            starts_at: '2026-10-08T09:30:00+01:00',
            ends_at: '2026-10-08T10:20:00+01:00',
            ...overrides,
        };
    }

    it('uma aula futura não é fechável', () => {
        const lesson = timing({ starts_at: '2026-10-08T11:30:00+01:00', ends_at: '2026-10-08T12:20:00+01:00' });

        expect(lessonQuickCloseState(lesson, now)).toBe('future');
        expect(isQuickClosable(lesson, now)).toBe(false);
    });

    it('uma aula começada e não terminada está em curso e é fechável', () => {
        const lesson = timing({ starts_at: '2026-10-08T10:40:00+01:00', ends_at: '2026-10-08T11:30:00+01:00' });

        expect(lessonQuickCloseState(lesson, now)).toBe('in_progress');
        expect(isQuickClosable(lesson, now)).toBe(true);
    });

    it('a hora de início exata já conta como começada', () => {
        expect(lessonQuickCloseState(timing({ starts_at: '2026-10-08T11:00:00+01:00', ends_at: '2026-10-08T11:50:00+01:00' }), now)).toBe('in_progress');
    });

    it('uma aula terminada hoje e aberta pede confirmação', () => {
        expect(lessonQuickCloseState(timing(), now)).toBe('ended');
        expect(isQuickClosable(timing(), now)).toBe(true);
    });

    it('uma aula de um dia anterior e aberta pede confirmação', () => {
        expect(lessonQuickCloseState(timing({ starts_at: '2026-10-05T09:30:00+01:00', ends_at: '2026-10-05T10:20:00+01:00' }), now)).toBe('ended');
    });

    it('sem fim conhecido, só termina quando o dia já passou', () => {
        expect(lessonQuickCloseState(timing({ ends_at: null }), now)).toBe('in_progress');
        expect(lessonQuickCloseState(timing({ starts_at: '2026-10-07T09:30:00+01:00', ends_at: null }), now)).toBe('ended');
    });

    it('uma aula lecionada está fechada', () => {
        expect(lessonQuickCloseState(timing({ status: 'taught', outcome: 'taught' }), now)).toBe('closed');
        expect(lessonQuickCloseState(timing({ status: 'taught' }), now)).toBe('closed');
    });

    it('uma aula com resultado registado está fechada', () => {
        expect(lessonQuickCloseState(timing({ outcome: 'teacher_absent' }), now)).toBe('closed');
        expect(isQuickClosable(timing({ outcome: 'class_external_activity' }), now)).toBe(false);
    });
});
