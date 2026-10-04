import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * A GUARDA DE ALTERAÇÕES POR GUARDAR, escrita uma vez (0.158.0) — usada pela
 * página da aula e pelo editor no cartão de Aulas e Sumários.
 *
 * Duas camadas, porque o browser só permite isto:
 *  - FECHAR O SEPARADOR, RECARREGAR, SAIR DA APLICAÇÃO: `beforeunload`, com a
 *    proteção nativa do browser (o texto do aviso é dele; a página só pede
 *    para ser perguntado);
 *  - NAVEGAR DENTRO DA APLICAÇÃO: a visita do Inertia é suspensa no evento
 *    `before` (cancelável) e fica à espera da decisão no diálogo «Tens
 *    alterações por guardar». As ações da própria página (mudar de vista, de
 *    filtro, de semana) pedem a mesma decisão através de `request()`.
 *
 * «Guardar e continuar» só retoma o que estava suspenso DEPOIS de a gravação
 * ser aceite (`save()` devolve `true`); em qualquer falha fica-se onde se
 * estava, com o texto intacto.
 *
 * A gravação da própria página é sempre uma saída legítima e nunca é
 * interrogada (`isSubmitting`).
 */
export function useUnsavedChangesGuard(options: {
    isDirty: () => boolean;
    isSubmitting: () => boolean;
    save: () => Promise<boolean>;
    discard: () => void;
}) {
    const pending = ref<(() => void) | null>(null);
    const saving = ref(false);
    let bypass = false;

    function run(action: (() => void) | null): void {
        if (action === null) {
            return;
        }

        bypass = true;

        try {
            action();
        } finally {
            bypass = false;
        }
    }

    /** Corre a ação já, ou suspende-a até à decisão quando há alterações por guardar. */
    function request(action: () => void): void {
        if (!options.isDirty() || options.isSubmitting()) {
            run(action);

            return;
        }

        pending.value = action;
    }

    function stay(): void {
        if (!saving.value) {
            pending.value = null;
        }
    }

    function leaveWithoutSaving(): void {
        const action = pending.value;
        pending.value = null;
        options.discard();
        run(action);
    }

    async function saveAndContinue(): Promise<boolean> {
        if (saving.value) {
            return false;
        }

        saving.value = true;

        try {
            const saved = await options.save();
            const action = pending.value;
            pending.value = null;

            if (saved) {
                run(action);
            }

            return saved;
        } finally {
            saving.value = false;
        }
    }

    function warnOnUnload(event: BeforeUnloadEvent): void {
        if (options.isDirty()) {
            event.preventDefault();
            // Ainda exigido por alguns browsers para mostrarem a proteção nativa.
            event.returnValue = '';
        }
    }

    let stopListening: (() => void) | null = null;

    onMounted(() => {
        window.addEventListener('beforeunload', warnOnUnload);
        stopListening = router.on('before', (event) => {
            if (bypass || !options.isDirty() || options.isSubmitting()) {
                return;
            }

            const visit = event.detail.visit;

            // Visitas que não saem da página (uma recarga parcial pedida pela
            // própria página) não perdem nada e passam.
            if (
                visit.only.length > 0 &&
                visit.method === 'get' &&
                visit.url.pathname === window.location.pathname
            ) {
                return;
            }

            event.preventDefault();
            pending.value = () =>
                router.visit(visit.url.href, {
                    method: visit.method,
                    data: visit.data,
                    replace: visit.replace,
                    preserveScroll: visit.preserveScroll,
                    preserveState: visit.preserveState,
                    only: visit.only,
                    except: visit.except,
                    headers: visit.headers,
                });
        });
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', warnOnUnload);
        stopListening?.();
        stopListening = null;
    });

    return {
        pending,
        saving,
        request,
        stay,
        leaveWithoutSaving,
        saveAndContinue,
    };
}
