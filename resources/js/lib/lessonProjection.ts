/**
 * A PORTA DE DADOS DA PROJEÇÃO DO SUMÁRIO (0.159.0).
 *
 * A projeção mostra-se na sala, à vista dos alunos. Por isso nada chega lá por
 * acaso: esta função é a ÚNICA ponte entre uma aula (que traz notas, estado,
 * assiduidade, acontecimentos do dia…) e o ecrã projetado, e devolve uma lista
 * branca de campos escritos um a um. Um campo novo na aula nunca aparece na
 * projeção sem alguém o acrescentar aqui, de propósito.
 *
 * Só leitura: não grava nada e não inventa nada — uma aula sem número fica sem
 * número, e um rascunho por guardar é mostrado como tal, nunca como gravado.
 */
import { fullDateLabel } from '@/lib/lessonDates';
import { lessonDate, lessonScope } from '@/lib/lessons';
import type { WeekLesson } from '@/lib/lessons';

export type LessonProjection = {
    lessonUlid: string;
    classLabel: string;
    subject: string;
    /** «Grupo T1» — só para grupos; nunca «Turma inteira» nem «Turma de apoio». */
    groupLabel: string | null;
    /** «Quinta-feira, 1 de outubro de 2026». */
    dateLabel: string;
    lessonNumber: number | null;
    summary: string | null;
    /** O texto projetado é um rascunho que ainda não foi guardado. */
    unsaved: boolean;
};

/** O rascunho aberto no editor do cartão, se o houver. */
export type ProjectionDraft = { text: string; dirty: boolean };

function nonBlank(text: string | null | undefined): string | null {
    return text !== null && text !== undefined && text.trim() !== ''
        ? text
        : null;
}

export function projectionFor(
    lesson: WeekLesson,
    draft: ProjectionDraft | null = null,
): LessonProjection {
    const scope = lessonScope(lesson);
    const unsaved = draft?.dirty === true;

    return {
        lessonUlid: lesson.ulid,
        classLabel: lesson.school_class.label,
        subject: lesson.subject,
        groupLabel: scope.kind === 'group' ? scope.label : null,
        dateLabel: fullDateLabel(lessonDate(lesson)),
        lessonNumber: lesson.lesson_number,
        summary: nonBlank(unsaved ? draft.text : lesson.summary),
        unsaved,
    };
}
