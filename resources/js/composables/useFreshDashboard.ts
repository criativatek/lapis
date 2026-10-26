import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import { consumeRestoration, onPersistedRestore } from '@/lib/historyRestoration';

/** Acima disto, os dados do painel consideram-se antigos. */
export const STALE_AFTER_MS = 5_000;

/**
 * Mantém o painel fresco. O Inertia repõe as props de `history.state` sem
 * perguntar ao servidor (botão «Atrás»), e um separador que volta a ficar
 * visível pode mostrar um painel de há horas.
 *
 * Revalida `dashboard` com uma visita SÍNCRONA ao próprio URL:
 * - ao montar, se a página foi reposta do histórico (`lib/historyRestoration`),
 *   QUALQUER que seja a idade das props: um ciclo painel → tarefa → «Atrás» de
 *   1 s mostrava o contador antigo; uma reposição vale um só pedido;
 * - ao montar, se `now` (o relógio do servidor quando as props foram feitas) tem
 *   mais de 5 s;
 * - quando o separador volta a ficar visível, pela mesma regra dos 5 s;
 * - quando o bfcache repõe a página já montada.
 * Uma primeira visita normal (props frescas, sem reposição) não faz pedidos.
 *
 * Nunca `router.reload()`: é assíncrono e pode repor props e URL antigos
 * (memória «Inertia: histórico e reload assíncrono»).
 */
export function useFreshDashboard(serverNow: () => string): void {
    const page = usePage();
    let inFlight = false;
    let stopPersisted: (() => void) | null = null;

    function visit(): void {
        if (inFlight) {
            return;
        }

        inFlight = true;
        router.get(
            page.url,
            {},
            {
                only: ['dashboard'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => {
                    inFlight = false;
                },
            },
        );
    }

    function isStale(): boolean {
        const age = Date.now() - Date.parse(serverNow());

        return !Number.isNaN(age) && age > STALE_AFTER_MS;
    }

    function revalidateIfStale(): void {
        if (isStale()) {
            visit();
        }
    }

    function onVisibility(): void {
        if (document.visibilityState === 'visible') {
            revalidateIfStale();
        }
    }

    onMounted(() => {
        // Consome sempre: a reposição vale uma só vez, mesmo que as props já fossem antigas.
        if (consumeRestoration()) {
            visit();
        } else {
            revalidateIfStale();
        }

        document.addEventListener('visibilitychange', onVisibility);
        stopPersisted = onPersistedRestore(visit);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisibility);
        stopPersisted?.();
    });
}
