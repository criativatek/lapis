import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { summarySearchRequestQuery } from '@/lib/lessonSummarySearch';
import type {
    SummarySearchResponse,
    SummarySearchState,
} from '@/lib/lessonSummarySearch';

/**
 * Os resultados de `GET lessons/search` para o estado da pesquisa no URL.
 *
 * SÓ A ÚLTIMA RESPOSTA CONTA. Cada pedido novo aborta o anterior e leva um
 * número de série; uma resposta que não seja a do último pedido é deitada
 * fora, para que «fra» nunca chegue depois de «frações» e lhe tome o lugar.
 * Enquanto a resposta do pedido atual não chega, a anterior pode ficar à
 * vista, mas `current` diz que já não é a desta pesquisa — e o ecrã não lhe
 * atribui as contagens.
 *
 * A ÚLTIMA RESPOSTA FICA NA SESSÃO DO BROWSER, para o regresso de uma aula: a
 * lista aparece logo (e o histórico consegue repor a posição em que se
 * estava) e é revalidada no servidor por cima. A chave leva QUEM e ONDE
 * (`scope`: utilizador, organização, ano letivo) — num computador partilhado,
 * outra pessoa, outra escola ou outro ano nunca veem a resposta guardada — e o
 * terminar de sessão apaga-a (`forgetSummarySearch`).
 *
 * Nunca lança: uma falha fica em `error`, e o ecrã diz o que aconteceu.
 */
export type SummarySearchStatus = 'idle' | 'loading' | 'ready' | 'error';
export type SummarySearchErrorKind = 'invalid' | 'session' | 'failed';

const CACHE_KEY = 'lapis.lessons.search.last';

/** Ao terminar a sessão: nada da pesquisa fica no browser. */
export function forgetSummarySearch(): void {
    try {
        window.sessionStorage.removeItem(CACHE_KEY);
    } catch {
        // Sem sessionStorage não havia nada guardado.
    }
}

function cached(key: string): SummarySearchResponse | null {
    try {
        const entry = JSON.parse(
            window.sessionStorage.getItem(CACHE_KEY) ?? 'null',
        ) as { key?: string; response?: SummarySearchResponse } | null;

        return entry?.key === key && entry.response ? entry.response : null;
    } catch {
        return null;
    }
}

function remember(key: string, response: SummarySearchResponse): void {
    try {
        window.sessionStorage.setItem(
            CACHE_KEY,
            JSON.stringify({ key, response }),
        );
    } catch {
        // Sem sessionStorage, o regresso mostra «A pesquisar…» até à resposta.
    }
}

function firstError(body: unknown): string | null {
    const errors = (body as { errors?: Record<string, string[] | string> })
        ?.errors;

    if (!errors) {
        return null;
    }

    const first = Object.values(errors)[0];

    return (Array.isArray(first) ? first[0] : first) ?? null;
}

export function useSummarySearch(
    state: () => SummarySearchState | null,
    scope: () => string,
) {
    const response = ref<SummarySearchResponse | null>(null);
    const status = ref<SummarySearchStatus>('idle');
    const error = ref<string | null>(null);
    /** `invalid` — o servidor recusou o pedido (422); `session` — a sessão terminou; `failed` — não respondeu como devia. */
    const errorKind = ref<SummarySearchErrorKind | null>(null);
    /** A query string da pesquisa pedida agora, e a da resposta que está à vista. */
    const requestedKey = ref<string | null>(null);
    const shownKey = ref<string | null>(null);
    /** A resposta à vista é a DESTA pesquisa (e não a anterior, à espera da nova). */
    const current = computed(
        () => shownKey.value !== null && shownKey.value === requestedKey.value,
    );

    let serial = 0;
    let controller: AbortController | null = null;
    let unmounted = false;

    function fail(key: string, kind: SummarySearchErrorKind, message: string): void {
        // A resposta à vista, se for de outra pesquisa, sai: as contagens e o
        // destaque dela nunca podem aparecer debaixo desta expressão.
        if (shownKey.value !== key) {
            response.value = null;
            shownKey.value = null;
        }

        error.value = message;
        errorKind.value = kind;
        status.value = 'error';
    }

    async function load(key: string): Promise<void> {
        controller?.abort();
        const mine = ++serial;
        const request = new AbortController();
        controller = request;
        status.value = 'loading';
        error.value = null;
        errorKind.value = null;

        try {
            const reply = await fetch(`/lessons/search?${key}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: request.signal,
            });

            if (reply.status === 422) {
                const message = firstError(await reply.json());

                if (mine === serial && !unmounted) {
                    fail(key, 'invalid', message ?? 'A pesquisa não é válida. Revê a expressão e os filtros.');
                }

                return;
            }

            if (reply.status === 401 || reply.status === 419) {
                if (mine === serial && !unmounted) {
                    fail(key, 'session', 'A tua sessão terminou. Recarrega a página para voltar a entrar.');
                }

                return;
            }

            if (!reply.ok) {
                throw new Error(String(reply.status));
            }

            const body = (await reply.json()) as SummarySearchResponse;

            if (mine !== serial || unmounted) {
                return;
            }

            response.value = body;
            shownKey.value = key;
            status.value = 'ready';
            remember(`${scope()}|${key}`, body);
        } catch (caught) {
            if (
                (caught as Error).name === 'AbortError' ||
                mine !== serial ||
                unmounted
            ) {
                return;
            }

            fail(key, 'failed', 'Não foi possível pesquisar nos sumários. Verifica a ligação e tenta outra vez.');
        }
    }

    function retry(): void {
        if (requestedKey.value !== null) {
            void load(requestedKey.value);
        }
    }

    watch(
        () => {
            const searching = state();

            return searching === null ? null : summarySearchRequestQuery(searching);
        },
        (key) => {
            requestedKey.value = key;

            if (key === null) {
                // Fora da pesquisa: nada pendente e nada à vista.
                controller?.abort();
                serial++;
                response.value = null;
                shownKey.value = null;
                status.value = 'idle';
                error.value = null;
                errorKind.value = null;

                return;
            }

            const stored = shownKey.value === null ? cached(`${scope()}|${key}`) : null;

            if (stored !== null) {
                response.value = stored;
                shownKey.value = key;
            }

            void load(key);
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        unmounted = true;
        controller?.abort();
    });

    return { response, status, error, errorKind, current, retry };
}
