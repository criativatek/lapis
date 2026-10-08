import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { CONTEXT_PAGE_SIZE, nextContextLimit } from '@/lib/lessonContext';
import type { LessonContextEntry, LessonContextResponse } from '@/lib/lessonContext';

/**
 * Carrega «Antes desta aula» de `GET lessons/{ulid}/preparation-context`.
 *
 * SÓ A ÚLTIMA RESPOSTA CONTA: cada pedido leva um número de série, e uma
 * resposta que chega depois de a aula ter mudado (ou de um pedido mais novo)
 * é deitada fora — senão o contexto de uma aula apareceria na seguinte. Nunca
 * lança: um erro fica em `error` e a página continua a funcionar.
 */
export function useLessonPreparationContext(ulid: Ref<string>) {
    const entries = ref<LessonContextEntry[]>([]);
    const hasMore = ref(false);
    const loading = ref(false);
    const error = ref(false);
    const limit = ref(CONTEXT_PAGE_SIZE);

    let serial = 0;
    let unmounted = false;

    async function load(): Promise<void> {
        const mine = ++serial;
        const requested = ulid.value;
        loading.value = true;
        error.value = false;

        try {
            const response = await fetch(`/lessons/${requested}/preparation-context?limit=${limit.value}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const data = (await response.json()) as LessonContextResponse;

            if (mine !== serial || unmounted) {
                return;
            }

            entries.value = data.lessons;
            hasMore.value = data.has_more;
        } catch {
            if (mine === serial && !unmounted) {
                error.value = true;
            }
        } finally {
            if (mine === serial && !unmounted) {
                loading.value = false;
            }
        }
    }

    function loadMore(): Promise<void> {
        limit.value = nextContextLimit(limit.value);

        return load();
    }

    watch(ulid, () => {
        // Outra aula: o que havia já não lhe pertence.
        entries.value = [];
        hasMore.value = false;
        limit.value = CONTEXT_PAGE_SIZE;
        void load();
    });

    onBeforeUnmount(() => {
        unmounted = true;
    });

    return { entries, hasMore, loading, error, load, loadMore };
}
