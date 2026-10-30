/**
 * O que as interrupções do calendário fazem às aulas já criadas — os tipos do
 * JSON do servidor e os pequenos auxiliares que os três ecrãs partilham
 * (a pré-visualização do responsável, o aviso do professor e a sua
 * regularização).
 *
 * SÓ FACTOS, NUNCA CONTEÚDO: o servidor não devolve texto de sumários, de
 * planeamentos nem de notas privadas — apenas datas, turmas, contagens e os
 * NOMES dos campos que viajam. Nada aqui o pede nem o mostra.
 */

export type ReconciliationLesson = {
    ulid: string;
    date: string;
    time: string;
    group: string | null;
    kind: 'A' | 'B' | 'C';
    retired: boolean;
    reasons: { code: string; label: string }[];
    exception_titles: string[];
    /** `to_lesson` é null quando o destino é uma aula que a correção cria a partir do horário (`new_lesson`). */
    moves: { to_lesson: string | null; to_date: string; to_time: string; new_lesson: boolean; fields: string[] }[];
    pending: boolean;
    pending_on_lesson?: string | null;
};

/**
 * Onde fica cada planeamento no FIM (só o professor o recebe): o de uma aula
 * retirada (`retired`) e, à parte, o que já estava numa aula seguinte e avança
 * (`advanced`). `pending`: fica como planeamento pendente nessa aula, porque o
 * horário acaba.
 */
export type PlanningResult = {
    origin: 'retired' | 'advanced';
    from_date: string;
    to_date: string | null;
    new_lesson: boolean;
    pending: boolean;
};

export type ReconciliationClass = {
    class: string | null;
    class_label: string;
    retired: number;
    shifted: number;
    awaiting_teacher: number;
    flagged: number;
    renumbered: number;
    gaps: number[];
    /** «Precisa de revisão», contado por razão (já em português, sem conteúdo). */
    review?: { count: number; label: string }[];
    planning?: PlanningResult[];
    lessons?: ReconciliationLesson[];
};

export type ReconciliationPlan = {
    plan_token: string;
    scope: string;
    operation: string;
    counts: { A: number; B: number; C: number };
    totals: { retired: number; shifted: number; awaiting_teacher: number; flagged: number };
    classes: ReconciliationClass[];
    gaps: { class: string | null; class_label: string; numbers: number[] }[];
    freed_days: string[];
    existing_on_freed_days: { count: number };
    changes_anything: boolean;
    message?: string;
};

export const STALE_PLAN_MESSAGE =
    'O calendário ou as aulas mudaram entretanto. Veja de novo a pré-visualização antes de confirmar.';

export const STALE_PLAN_MESSAGE_TEACHER =
    'O calendário ou as aulas mudaram entretanto. Vê de novo a pré-visualização antes de confirmar.';

/** A pré-visualização do responsável falhou (erro do servidor, ligação, espera por um bloqueio). */
export const IMPACT_UNAVAILABLE_MESSAGE =
    'Não foi possível verificar as aulas já criadas. Se guardar mesmo assim, as aulas desses dias ficam por corrigir e os professores das turmas passam a ver um aviso em «Aulas e Sumários» para as corrigirem. Guardar sem alterar as aulas?';

/** A nova verificação, depois de um plano obsoleto, falhou: o plano antigo já não serve para confirmar. */
export const IMPACT_REFRESH_FAILED_MESSAGE =
    'Não foi possível voltar a verificar as aulas. Pode guardar sem alterar as aulas, ou cancelar e tentar mais tarde.';

/** Uma recusa (422) que não é de um campo do formulário — não tem onde aparecer por baixo de um campo. */
export const GENERAL_REFUSAL_MESSAGE = 'Não foi possível guardar. Nada foi alterado.';

/** As aulas por dar JÁ EXISTENTES que mudam de número, somadas por turma. */
export function renumberedCount(plan: Pick<ReconciliationPlan, 'classes'>): number {
    return plan.classes.reduce((total, item) => total + (item.renumbered ?? 0), 0);
}

/** «3 aulas por dar mudam de número» — fecham o buraco deixado pelas que saem. */
export function renumberedSentence(count: number): string {
    return `${plural(count, 'aula por dar muda', 'aulas por dar mudam')} de número.`;
}

/** Porque é que a numeração muda, numa frase — dita uma vez, junto das contagens. */
export const RENUMBERING_REASON = 'As aulas por dar ocupam o lugar das retiradas; as lecionadas mantêm o número.';

/** «A lição 5 fica sem aula.» / «As lições 5 e 6 ficam sem aula.» */
export function gapsSentence(numbers: number[]): string {
    const label = gapsLabel(numbers);

    return `${label.charAt(0).toUpperCase()}${label.slice(1)} ${numbers.length === 1 ? 'fica' : 'ficam'} sem aula.`;
}

/** «04/01» — de «2027-01-04». */
export function shortDate(date: string): string {
    return `${date.slice(8, 10)}/${date.slice(5, 7)}`;
}

/** «04/01 e 05/01», «04/01, 05/01 e 11/01». */
function joinDates(dates: string[]): string {
    const labels = dates.map(shortDate);

    return labels.length === 1 ? labels[0] : `${labels.slice(0, -1).join(', ')} e ${labels[labels.length - 1]}`;
}

/**
 * O RESULTADO DO PLANEAMENTO, em frases: uma por planeamento das aulas
 * retiradas («O planeamento de segunda, 21/12 passa para segunda, 04/01.») e,
 * agrupado, o que já estava nas aulas seguintes e avança — nunca a cascata de
 * passos («o de 04/01 passa para 11/01…»), que fazia parecer que havia mais
 * planeamentos a mexer do que aulas retiradas.
 */
export function planningSentences(planning: PlanningResult[]): { retired: string[]; advanced: string[] } {
    const retired: string[] = [];
    const advancing: PlanningResult[] = [];
    const advanced: string[] = [];

    for (const item of planning) {
        if (item.origin === 'advanced') {
            if (item.pending) {
                advanced.push(
                    `O planeamento que já estava na aula de ${dayLabel(item.from_date)} fica lá guardado como planeamento pendente, porque não há mais aulas no horário.`,
                );
            } else if (item.to_date !== null) {
                advancing.push(item);
            }

            continue;
        }

        if (item.to_date === null) {
            continue;
        }

        retired.push(
            item.pending
                ? `O planeamento de ${dayLabel(item.from_date)} fica guardado como planeamento pendente na aula de ${dayLabel(item.to_date)}, porque não há mais aulas no horário.`
                : `O planeamento de ${dayLabel(item.from_date)} passa para ${dayLabel(item.to_date)}${item.new_lesson ? ' (aula criada a partir do horário)' : ''}.`,
        );
    }

    if (advancing.length > 0) {
        advanced.unshift(
            `O planeamento que já estava ${advancing.length === 1 ? 'na aula' : 'nas aulas'} de ${joinDates(advancing.map((item) => item.from_date))} avança para a aula seguinte: ${joinDates(advancing.map((item) => item.to_date as string))}.`,
        );
    }

    return { retired, advanced };
}

/**
 * Separa os erros de um 422 em erros de CAMPO (os que o formulário mostra por
 * baixo de cada campo) e o resto (`exception`, `plan_token`, `lesson_number`,
 * `academic_year`…), que precisa de uma mensagem geral para não se perder.
 */
export function splitErrors(
    errors: Record<string, string>,
    fields: readonly string[],
): { fields: Record<string, string>; general: string[] } {
    const result: { fields: Record<string, string>; general: string[] } = { fields: {}, general: [] };

    for (const [key, message] of Object.entries(errors)) {
        if (fields.includes(key)) {
            result.fields[key] = message;
        } else if (message) {
            result.general.push(message);
        }
    }

    return result;
}

/** Há alguma coisa que o responsável deva ver antes de gravar? */
export function planNeedsDialog(plan: ReconciliationPlan): boolean {
    return (
        plan.changes_anything ||
        plan.totals.awaiting_teacher > 0 ||
        plan.totals.flagged > 0 ||
        plan.existing_on_freed_days.count > 0
    );
}

export function plural(count: number, one: string, many: string): string {
    return count === 1 ? `1 ${one}` : `${count} ${many}`;
}

/** «a lição 5», «as lições 5 e 6», «as lições 5, 6 e 9». */
export function gapsLabel(numbers: number[]): string {
    if (numbers.length === 1) {
        return `a lição ${numbers[0]}`;
    }

    return `as lições ${numbers.slice(0, -1).join(', ')} e ${numbers[numbers.length - 1]}`;
}

const dayFormatter = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'short',
    day: 'numeric',
    month: 'numeric',
    timeZone: 'UTC',
});

/** «qua., 24/12» — de «2026-12-24». */
export function dayLabel(date: string): string {
    return dayFormatter.format(new Date(`${date}T00:00:00Z`));
}

export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export type JsonResult<T> = { status: number; ok: boolean; body: T | null };

export async function requestJson<T>(url: string, method: 'GET' | 'POST', payload?: unknown): Promise<JsonResult<T>> {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(method === 'POST' ? { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() } : {}),
        },
        ...(payload === undefined ? {} : { body: JSON.stringify(payload) }),
    });
    const body = (await response.json().catch(() => null)) as T | null;

    return { status: response.status, ok: response.ok, body };
}

export function firstError(body: unknown): string | null {
    const errors = (body as { errors?: Record<string, string | string[]> } | null)?.errors;

    if (!errors) {
        return null;
    }

    for (const value of Object.values(errors)) {
        const message = Array.isArray(value) ? value[0] : value;

        if (message) {
            return message;
        }
    }

    return null;
}
