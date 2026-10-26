import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

/** Acima disto, os dados do painel consideram-se antigos. */
export const STALE_AFTER_MS = 30_000;

/**
 * Mantém o painel fresco ao voltar de uma tarefa. O Inertia repõe as props de
 * `history.state` sem perguntar ao servidor (botão «Atrás»), e um separador que
 * volta a ficar visível pode mostrar um painel de há horas.
 *
 * Ao montar e quando o separador volta a ficar visível, se `now` (o relógio do
 * servidor na altura em que as props foram feitas) tem mais de 30 s, pede
 * `dashboard` outra vez com uma visita SÍNCRONA ao próprio URL. Nunca
 * `router.reload()`: é assíncrono e pode repor props e URL antigos (memória
 * «Inertia: histórico e reload assíncrono»).
 */
export function useFreshDashboard(serverNow: () => string): void {
    const page = usePage();
    let inFlight = false;

    function revalidate(): void {
        const age = Date.now() - Date.parse(serverNow());

        if (inFlight || Number.isNaN(age) || age <= STALE_AFTER_MS) {
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

    function onVisibility(): void {
        if (document.visibilityState === 'visible') {
            revalidate();
        }
    }

    onMounted(() => {
        revalidate();
        document.addEventListener('visibilitychange', onVisibility);
    });

    onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisibility));
}
