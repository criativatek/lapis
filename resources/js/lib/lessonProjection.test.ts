/**
 * A projeção é a lista branca do que os alunos podem ver: as chaves são
 * exatas, e nada interno da aula chega lá, mesmo que a aula o traga.
 */
import { describe, expect, it } from 'vitest';
import { fullDateLabel } from '@/lib/lessonDates';
import { projectionFor } from '@/lib/lessonProjection';
import type { WeekLesson } from '@/lib/lessons';

function makeLesson(overrides: Partial<WeekLesson> = {}): WeekLesson {
    return {
        ulid: 'lesson-a',
        starts_at: '2026-10-01T09:30:00+01:00',
        ends_at: '2026-10-01T10:20:00+01:00',
        school_class: {
            ulid: 'class-a',
            label: '8.º B',
            is_support_class: false,
        },
        context_label: '8.º B',
        class_group_label: null,
        class_group_id: null,
        subject: 'Físico-Química',
        status: 'taught',
        status_label: 'Lecionada',
        has_summary: true,
        summary: 'Primeiro parágrafo.\nContinua.\n\nSegundo.',
        summary_version: 3,
        summary_reviewed: true,
        identity_tone: 'violet',
        day_events: [],
        lesson_number: 8,
        outcome: null,
        outcome_label: null,
        can_delete: true,
        can_clear_summary: true,
        attendance_recorded: true,
        absent_count: 2,
        ...overrides,
    };
}

const KEYS = [
    'classLabel',
    'dateLabel',
    'groupLabel',
    'lessonNumber',
    'lessonUlid',
    'subject',
    'summary',
    'unsaved',
];

describe('fullDateLabel', () => {
    it('escreve a data por extenso, com ano, em pt-PT', () => {
        expect(fullDateLabel('2026-10-01')).toBe(
            'Quinta-feira, 1 de outubro de 2026',
        );
        expect(fullDateLabel('2026-12-31')).toBe(
            'Quinta-feira, 31 de dezembro de 2026',
        );
    });
});

describe('projectionFor', () => {
    it('devolve só as chaves da lista branca, mesmo que a aula traga campos extra', () => {
        const lesson = {
            ...makeLesson(),
            teacher_notes: 'nota privada',
            resources: ['ficha.pdf'],
            homework: 'TPC secreto',
            students: ['Maria'],
        } as WeekLesson;

        const projection = projectionFor(lesson);

        expect(Object.keys(projection).sort()).toEqual(KEYS);
        expect(JSON.stringify(projection)).not.toMatch(
            /nota privada|ficha\.pdf|TPC secreto|Maria|Lecionada|taught/,
        );
    });

    it('usa a data da aula (hora local) com ano', () => {
        expect(projectionFor(makeLesson()).dateLabel).toBe(
            'Quinta-feira, 1 de outubro de 2026',
        );
        // 23:30 locais continuam no mesmo dia, mesmo em UTC já ser o dia seguinte.
        expect(
            projectionFor(
                makeLesson({ starts_at: '2026-10-01T23:30:00-03:00' }),
            ).dateLabel,
        ).toBe('Quinta-feira, 1 de outubro de 2026');
    });

    it('passa o contexto e o número tal e qual', () => {
        const projection = projectionFor(makeLesson());

        expect(projection).toMatchObject({
            lessonUlid: 'lesson-a',
            classLabel: '8.º B',
            subject: 'Físico-Química',
            lessonNumber: 8,
            groupLabel: null,
        });
    });

    it('um número em falta fica em falta — nunca se inventa um', () => {
        expect(
            projectionFor(makeLesson({ lesson_number: null })).lessonNumber,
        ).toBeNull();
    });

    it('os grupos T1 e T2 mostram o seu grupo e partilham o número da divisão', () => {
        const t1 = projectionFor(
            makeLesson({
                class_group_id: 1,
                class_group_label: 'T1',
                lesson_number: 5,
            }),
        );
        const t2 = projectionFor(
            makeLesson({
                class_group_id: 2,
                class_group_label: 'T2',
                lesson_number: 5,
            }),
        );

        expect(t1.groupLabel).toBe('Grupo T1');
        expect(t2.groupLabel).toBe('Grupo T2');
        expect(t1.lessonNumber).toBe(5);
        expect(t2.lessonNumber).toBe(5);
    });

    it('a turma inteira e a turma de apoio não têm etiqueta de grupo', () => {
        expect(projectionFor(makeLesson()).groupLabel).toBeNull();
        expect(
            projectionFor(
                makeLesson({
                    school_class: {
                        ulid: 'c',
                        label: 'Apoio',
                        is_support_class: true,
                    },
                }),
            ).groupLabel,
        ).toBeNull();
    });

    it('um rascunho alterado projeta o texto do editor, marcado como por guardar', () => {
        const projection = projectionFor(makeLesson(), {
            text: 'Texto novo\n\nainda por guardar',
            dirty: true,
        });

        expect(projection.summary).toBe('Texto novo\n\nainda por guardar');
        expect(projection.unsaved).toBe(true);
    });

    it('um rascunho alterado que ficou vazio projeta «sem sumário», ainda por guardar', () => {
        const projection = projectionFor(makeLesson(), {
            text: '  \n ',
            dirty: true,
        });

        expect(projection.summary).toBeNull();
        expect(projection.unsaved).toBe(true);
    });

    it('um rascunho sem alterações projeta o texto guardado', () => {
        const projection = projectionFor(makeLesson(), {
            text: 'Primeiro parágrafo.\nContinua.\n\nSegundo.',
            dirty: false,
        });

        expect(projection.summary).toBe(
            'Primeiro parágrafo.\nContinua.\n\nSegundo.',
        );
        expect(projection.unsaved).toBe(false);
    });

    it('sem sumário guardado, o sumário é null', () => {
        expect(
            projectionFor(makeLesson({ summary: null, has_summary: false }))
                .summary,
        ).toBeNull();
        expect(
            projectionFor(makeLesson({ summary: '   ' })).summary,
        ).toBeNull();
    });
});
