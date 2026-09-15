import { describe, expect, it } from 'vitest';
import { lessonDisplayState } from '@/lib/lessons';
import type { LessonStateSource } from '@/lib/lessons';

/**
 * 0.146.1 — uma aula com resultado registado está fechada: o cartão nunca
 * mostra «Preparado» ao lado de «Professor ausente».
 */
function source(overrides: Partial<LessonStateSource> = {}): LessonStateSource {
    return { status: 'prepared', status_label: 'Preparado', outcome: null, outcome_label: null, ...overrides };
}

describe('lessonDisplayState', () => {
    it('sem resultado mostra a preparação', () => {
        expect(lessonDisplayState(source())).toEqual({ value: 'prepared', label: 'Preparado' });
        expect(lessonDisplayState(source({ status: 'preparation', status_label: 'Por preparar' }))).toEqual({
            value: 'preparation',
            label: 'Por preparar',
        });
    });

    it('professor ausente substitui «Preparado»', () => {
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
            lessonDisplayState(source({ status: 'taught', status_label: 'Lecionado', outcome: 'taught', outcome_label: 'Lecionada' })),
        ).toEqual({ value: 'taught', label: 'Lecionado' });
    });
});
