import { lessonNumberLabel } from '@/lib/lessons';

/**
 * O NÚMERO DA LIÇÃO NOS EDITORES (0.169.0).
 *
 * O número é sugerido pelo servidor; o professor pode ajustá-lo. Esta lógica é
 * escrita UMA vez porque dois editores a usam (a página da aula e o cartão da
 * semana) e não podem divergir na pergunta que mais importa: «o número mudou?».
 * Só quando muda é que o pedido de gravação leva chaves `lesson_number*` — sem
 * elas o servidor não toca na numeração (uma aula automática continua
 * automática, uma manual continua manual).
 */

/** O que o professor tem no campo. Texto, porque é o que um `<input>` entrega. */
export type LessonNumberDraft = {
    start: string;
    /**
     * LEGADO: o fim de um intervalo gravado até à 0.171.0 («Lições 12–13»). Só se
     * lê. É NULL em todas as aulas novas (0.172.0: uma aula, um número) e
     * qualquer edição do número apaga-o — a aula passa a uma só lição.
     */
    legacyEnd: number | null;
    /** «Repor numeração automática» foi carregado: ao guardar, volta à sugestão. */
    automatic: boolean;
};

/** O mínimo que uma aula tem de dizer sobre o seu número. */
export type LessonNumberSource = {
    lesson_number: number | null;
    lesson_number_end: number | null;
    lesson_number_manual: boolean;
    /** Contenção da recuperação: o número só se lê (0.169.0). */
    numbering_frozen?: boolean;
    outcome: 'taught' | 'teacher_absent' | 'class_external_activity' | null;
};

/** As chaves que o pedido de gravação (PUT/PATCH do sumário) aceita. */
export type LessonNumberPayload = {
    lesson_number?: number;
    lesson_number_automatic?: boolean;
};

export type LessonNumberEffect = {
    ulid: string;
    starts_at: string;
    context_label: string;
    from: number | null;
    from_end: number | null;
    /** NULL = a aula fica sem número. */
    to: number | null;
    to_end: number | null;
    recorded: boolean;
};

export type LessonNumberPreview = {
    changed: boolean;
    result: { start: number | null; end: number | null; manual: boolean };
    conflicts: string[];
    effects: LessonNumberEffect[];
    warnings: string[];
    requires_confirmation: boolean;
    confirmation: string | null;
    following_from: number | null;
};

/** Os erros do número (0.172.0: já não há fim do intervalo). */
export type LessonNumberErrors = { number: string | null };

export const NO_NUMBER_ERRORS: LessonNumberErrors = { number: null };

/** Professor ausente: a aula não tem lição, o campo não se oferece. */
export function canSetLessonNumber(lesson: Pick<LessonNumberSource, 'outcome'>): boolean {
    return lesson.outcome !== 'teacher_absent';
}

export function numberDraftFromLesson(lesson: Pick<LessonNumberSource, 'lesson_number' | 'lesson_number_end'>): LessonNumberDraft {
    return {
        start: lesson.lesson_number === null ? '' : String(lesson.lesson_number),
        legacyEnd: lesson.lesson_number_end,
        automatic: false,
    };
}

function toInteger(value: string): number | null {
    const text = value.trim();

    return /^\d+$/.test(text) ? Number.parseInt(text, 10) : null;
}

/** O número que o rascunho exprime; NULL se o campo está vazio ou ilegível. */
export function draftNumber(draft: LessonNumberDraft): number | null {
    return toInteger(draft.start);
}

/**
 * O rascunho difere do que a aula tem? É isto que conta como «alterações por
 * guardar». Editar o número de uma aula legada (mesmo para o mesmo valor) conta:
 * é o gesto que a converte a uma só lição.
 */
export function numberDraftDirty(draft: LessonNumberDraft, original: LessonNumberDraft): boolean {
    if (draft.automatic) {
        return true;
    }

    const now = draftNumber(draft);
    const before = draftNumber(original);

    // Um campo apagado (ou ilegível) numa aula que tinha número é uma alteração
    // — que a validação recusa ao guardar, em vez de a ignorar em silêncio.
    if (now === null && before !== null) {
        return true;
    }

    return now !== before || draft.legacyEnd !== original.legacyEnd;
}

/**
 * Validação ANTES de ir ao servidor: poupa um pedido, e diz o que está mal
 * junto do campo. O servidor valida de novo (é ele que manda).
 */
export function validateNumberDraft(draft: LessonNumberDraft): LessonNumberErrors {
    if (draft.automatic) {
        return NO_NUMBER_ERRORS;
    }

    const start = toInteger(draft.start);

    if (start === null || start < 1 || start > 9999) {
        return { number: 'Indique o número da lição (um número inteiro positivo).' };
    }

    return NO_NUMBER_ERRORS;
}

/** O corpo da pré-visualização (`POST /lessons/{ulid}/number/preview`). */
export function previewBody(draft: LessonNumberDraft): { mode: 'automatic' } | { mode: 'manual'; start: number } {
    if (draft.automatic) {
        return { mode: 'automatic' };
    }

    return { mode: 'manual', start: draftNumber(draft) as number };
}

/** As chaves a juntar ao pedido de gravação — só quando o número mudou. */
export function numberSavePayload(draft: LessonNumberDraft, confirmation: string | null): LessonNumberPayload & { lesson_number_confirmation?: string } {
    const payload: LessonNumberPayload & { lesson_number_confirmation?: string } = draft.automatic
        ? { lesson_number_automatic: true }
        : { lesson_number: draftNumber(draft) as number };

    if (confirmation !== null) {
        payload.lesson_number_confirmation = confirmation;
    }

    return payload;
}

/** Há algo que o professor tem de ver e confirmar antes de gravar? */
export function previewNeedsConfirmation(preview: LessonNumberPreview): boolean {
    return preview.effects.some((effect) => effect.recorded) || preview.warnings.length > 0;
}

const dayMonthFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    timeZone: 'Europe/Lisbon',
});

/** «22/10 · 8.º F · Lição 2 → Lição 12» — ou «… → fica sem número». */
export function effectLine(effect: LessonNumberEffect): string {
    const from = lessonNumberLabel(effect.from, effect.from_end) ?? 'sem número';
    const to = lessonNumberLabel(effect.to, effect.to_end) ?? 'fica sem número';

    return `${dayMonthFormatter.format(new Date(effect.starts_at))} · ${effect.context_label} · ${from} → ${to}`;
}

export type LessonNumberPreviewResult =
    | { kind: 'ok'; preview: LessonNumberPreview }
    | { kind: 'invalid'; errors: LessonNumberErrors }
    | { kind: 'failed'; message: string };

function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

/**
 * Pede ao servidor o que a alteração faria — sem escrever nada. Nunca lança:
 * um 422 vira erros do campo, qualquer outra falha vira uma mensagem.
 */
export async function requestNumberPreview(lessonUlid: string, draft: LessonNumberDraft): Promise<LessonNumberPreviewResult> {
    try {
        const response = await fetch(`/lessons/${lessonUlid}/number/preview`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(previewBody(draft)),
        });

        if (response.status === 422) {
            const body = (await response.json()) as { errors?: Record<string, string[]> };
            const errors = body.errors ?? {};

            return {
                kind: 'invalid',
                errors: {
                    number: errors.lesson_number?.[0] ?? errors.start?.[0] ?? errors.mode?.[0] ?? null,
                },
            };
        }

        if (!response.ok) {
            return { kind: 'failed', message: 'Não foi possível verificar o número da lição. O teu texto continua aqui — tenta outra vez.' };
        }

        return { kind: 'ok', preview: (await response.json()) as LessonNumberPreview };
    } catch {
        return { kind: 'failed', message: 'Não foi possível verificar o número da lição: a ligação falhou. O teu texto continua aqui — tenta outra vez.' };
    }
}

/** Os erros do número que o servidor devolveu na gravação (`errors` do Inertia). */
export function numberErrorsFrom(errors: Record<string, string | undefined>): LessonNumberErrors | null {
    const number = errors.lesson_number ?? null;

    return number === null ? null : { number };
}
