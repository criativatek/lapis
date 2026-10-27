import type { Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import type { BlockId, BlockWidth } from '@/lib/dashboardLayout';
import { BLOCK_LABEL, DEFAULT_ORDER, layoutBlocks, mergeVisibleOrder, moveBlock, readStoredLayout, visibleOrder, writeStoredLayout } from '@/lib/dashboardLayout';

/**
 * A ordem dos blocos do Painel: a guardada (por utilizador e organização), a
 * pré-visualização enquanto se organiza e as ações do painel «Organizar».
 *
 * Nunca essencial: sem `localStorage` a ordem vale só nesta visita. Os blocos
 * ausentes (plano, sem dados) não aparecem nem se ordenam, mas mantêm o seu
 * lugar na ordem guardada para quando voltarem.
 */
export function useDashboardLayout(options: { storageKey: Ref<string>; available: Ref<readonly BlockId[]>; widths: Ref<Readonly<Record<BlockId, BlockWidth>>> }) {
    const stored = ref<BlockId[]>(readStoredLayout(options.storageKey.value));
    const draft = ref<BlockId[] | null>(null);
    const announcement = ref('');

    // Outra organização ou outra pessoa no mesmo browser: outra chave, outra ordem.
    watch(options.storageKey, (key) => {
        stored.value = readStoredLayout(key);
        draft.value = null;
    });

    const editing = computed(() => draft.value !== null);
    const order = computed<BlockId[]>(() => draft.value ?? visibleOrder(stored.value, options.available.value));
    const blocks = computed(() => layoutBlocks(order.value, options.widths.value));
    const canOrganise = computed(() => options.available.value.length > 1);

    function start(): void {
        draft.value = [...order.value];
        announcement.value = '';
    }

    function move(id: BlockId, delta: -1 | 1): void {
        if (draft.value === null) {
            return;
        }

        draft.value = moveBlock(draft.value, id, delta);
        announcement.value = `${BLOCK_LABEL[id]}: posição ${draft.value.indexOf(id) + 1} de ${draft.value.length}`;
    }

    /** Só a pré-visualização: nada se persiste até «Guardar». */
    function reset(): void {
        draft.value = visibleOrder(DEFAULT_ORDER, options.available.value);
        announcement.value = 'Disposição original reposta. Guarde para a manter.';
    }

    function cancel(): void {
        draft.value = null;
    }

    function save(): void {
        if (draft.value === null) {
            return;
        }

        stored.value = mergeVisibleOrder(stored.value, draft.value);
        writeStoredLayout(options.storageKey.value, stored.value);
        draft.value = null;
    }

    return { editing, order, blocks, canOrganise, announcement, start, move, reset, cancel, save };
}
