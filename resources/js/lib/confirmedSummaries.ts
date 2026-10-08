/**
 * GRAVAÇÕES DE SUMÁRIO CONFIRMADAS PELO SERVIDOR, por sessão do browser.
 *
 * Porque existe: o Inertia repõe uma página do histórico (botão «Atrás», ou o
 * «Voltar às aulas da semana» que usa `history.back()`) com as props de ANTES,
 * sem ir ao servidor — a semana voltava a mostrar o sumário antigo depois de o
 * professor o ter gravado. E nada impedia uma resposta mais antiga do que a
 * gravação de repor o texto anterior.
 *
 * A chave certa é `lessons.summary_version`: sobe em TODA a escrita do sumário
 * e já viaja em cada `WeekLesson`. Cada gravação confirmada fica aqui com a sua
 * versão; uma aula cujas props estão atrás dessa versão é mostrada com o texto
 * confirmado, e a entrada desaparece assim que as props a alcançam. O servidor
 * continua a ser a fonte da verdade — isto só impede que o ecrã ande para trás.
 *
 * Guardado em memória reativa e em `sessionStorage` (sobrevive à navegação
 * completa e ao regresso pelo histórico). Qualquer acesso ao storage pode
 * lançar (modo privado, quota): a página funciona na mesma, só em memória.
 */
import { reactive } from 'vue';

const STORAGE_KEY = 'lapis.lessons.confirmedSummaries';
const STALE_KEY = 'lapis.lessons.stale';
/** Um limite folgado: uma sessão não grava centenas de sumários sem reler a semana. */
const MAX_ENTRIES = 200;

export type ConfirmedSummary = { ulid: string; content: string; version: number };

type SummaryCarrier = {
    ulid: string;
    summary: string | null;
    has_summary: boolean;
    summary_version: number;
};

const entries = reactive<Record<string, ConfirmedSummary>>({});
let staleInMemory = false;

function persist(): void {
    try {
        window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(entries));
    } catch {
        // Sem sessionStorage fica só em memória.
    }
}

function remember(entry: ConfirmedSummary): boolean {
    const current = entries[entry.ulid];

    if (current !== undefined && current.version >= entry.version) {
        return false;
    }

    // Reinsere no fim, para o corte do limite descartar os mais antigos.
    delete entries[entry.ulid];
    entries[entry.ulid] = entry;

    const keys = Object.keys(entries);

    for (const key of keys.slice(0, Math.max(0, keys.length - MAX_ENTRIES))) {
        delete entries[key];
    }

    return true;
}

/** Relê o que a sessão já guardou (regresso pelo histórico, nova página). */
export function hydrateConfirmedSummaries(): void {
    try {
        const raw = JSON.parse(window.sessionStorage.getItem(STORAGE_KEY) ?? 'null') as Record<string, unknown> | null;

        if (raw === null || typeof raw !== 'object') {
            return;
        }

        for (const value of Object.values(raw)) {
            const entry = value as Partial<ConfirmedSummary> | null;

            if (
                entry !== null &&
                typeof entry?.ulid === 'string' &&
                typeof entry.content === 'string' &&
                typeof entry.version === 'number'
            ) {
                remember({ ulid: entry.ulid, content: entry.content, version: entry.version });
            }
        }
    } catch {
        // Storage ilegível ou JSON estragado: parte-se do que está em memória.
    }
}

/** Regista a última gravação CONFIRMADA; só conta se a versão for maior do que a já registada. */
export function recordConfirmedSummary(ulid: string, content: string, version: number): void {
    if (remember({ ulid, content, version })) {
        persist();
    }
}

/** A aula com o texto confirmado por cima, SE as props estiverem atrás dessa gravação. */
export function withConfirmedSummary<T extends SummaryCarrier>(lesson: T): T {
    const entry = entries[lesson.ulid];

    if (entry === undefined || entry.version <= lesson.summary_version) {
        return lesson;
    }

    return {
        ...lesson,
        summary: entry.content.trim() === '' ? null : entry.content,
        has_summary: entry.content.trim() !== '',
        summary_version: entry.version,
    };
}

export function withConfirmedSummaries<T extends SummaryCarrier>(lessons: readonly T[]): T[] {
    return lessons.map((lesson) => withConfirmedSummary(lesson));
}

/** Alguma destas aulas tem as props atrás de uma gravação confirmada? */
export function hasPendingConfirmations(lessons: readonly SummaryCarrier[]): boolean {
    return lessons.some((lesson) => {
        const entry = entries[lesson.ulid];

        return entry !== undefined && entry.version > lesson.summary_version;
    });
}

/** Esquece as entradas que as props já alcançaram (o servidor tem a mesma versão ou mais). */
export function pruneCaughtUp(lessons: readonly Pick<SummaryCarrier, 'ulid' | 'summary_version'>[]): void {
    let changed = false;

    for (const lesson of lessons) {
        const entry = entries[lesson.ulid];

        if (entry !== undefined && entry.version <= lesson.summary_version) {
            delete entries[lesson.ulid];
            changed = true;
        }
    }

    if (changed) {
        persist();
    }
}

/** Algo mudou nas aulas noutra página (gravar, limpar, lecionar, resultado, assiduidade, eliminar). */
export function markLessonsStale(): void {
    staleInMemory = true;

    try {
        window.sessionStorage.setItem(STALE_KEY, '1');
    } catch {
        // O sinal em memória chega para a mesma sessão de página.
    }
}

/** Lê e limpa o sinal: devolve `true` uma só vez por alteração. */
export function consumeLessonsStale(): boolean {
    let stale = staleInMemory;
    staleInMemory = false;

    try {
        if (window.sessionStorage.getItem(STALE_KEY) !== null) {
            stale = true;
            window.sessionStorage.removeItem(STALE_KEY);
        }
    } catch {
        // idem
    }

    return stale;
}

/** Só para testes: esquece tudo, na memória e no storage. */
export function resetConfirmedSummaries(): void {
    for (const key of Object.keys(entries)) {
        delete entries[key];
    }

    staleInMemory = false;

    try {
        window.sessionStorage.removeItem(STORAGE_KEY);
        window.sessionStorage.removeItem(STALE_KEY);
    } catch {
        // nada a limpar
    }
}
