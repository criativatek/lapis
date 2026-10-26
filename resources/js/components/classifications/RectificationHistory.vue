<script setup lang="ts">
import { PencilLine } from '@lucide/vue';
import { ref } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { entryValueText, loadRectification, rectificationDateTime } from '@/lib/rectification';
import type { RectificationHistoryEntry } from '@/lib/rectification';

/**
 * O marcador de «esta classificação foi retificada» e o histórico que abre.
 *
 * Só aparece quando há retificações, e aparece a quem apenas lê: ver o que
 * mudou não depende de poder retificar. O histórico não vem com a página (custa
 * consultas por linha); pede-se ao abrir.
 *
 * O ícone é pequeno de propósito (discreto), mas o ALVO não: 44px abaixo de `md`
 * e 36px a partir daí (DESIGN.md e convenção do repositório). As margens
 * negativas devolvem à linha o espaço que o alvo ocupa, para a célula não crescer.
 */
const props = defineProps<{
    /** Quantas retificações a célula já teve; sem nenhuma, nada se desenha. */
    count: number;
    /** `classifications.rectification` da classificação vigente. */
    historyUrl: string;
    studentName?: string;
}>();

const open = ref(false);
const loading = ref(false);
const failed = ref(false);
const history = ref<RectificationHistoryEntry[]>([]);

async function show(): Promise<void> {
    open.value = true;
    loading.value = true;
    failed.value = false;

    try {
        history.value = (await loadRectification(props.historyUrl)).history;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <button
        v-if="count > 0"
        type="button"
        class="-my-3 -ml-2 -mr-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded align-middle text-muted-foreground hover:bg-muted/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring md:-my-2 md:-ml-1.5 md:-mr-2 md:min-h-9 md:min-w-9"
        title="Classificação retificada"
        aria-label="Classificação retificada"
        data-testid="rectified-marker"
        @click="show"
    >
        <PencilLine class="size-3" />
    </button>

    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Classificação retificada</DialogTitle>
                <DialogDescription>
                    <template v-if="studentName">{{ studentName }} — </template>do mais antigo para o mais recente.
                </DialogDescription>
            </DialogHeader>

            <p v-if="loading" class="text-sm text-muted-foreground">A carregar o histórico…</p>
            <p v-else-if="failed" role="alert" class="text-sm text-red-600 dark:text-red-500">Não foi possível carregar o histórico. Tente novamente.</p>
            <ol v-else class="space-y-3" data-testid="rectification-history">
                <li
                    v-for="entry in history"
                    :key="entry.ulid"
                    class="rounded-md border border-border px-3 py-2 text-sm"
                    :class="{ 'border-primary': entry.is_live }"
                    data-testid="rectification-entry"
                >
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="font-semibold tabular-nums">{{ entryValueText(entry.final) }}</span>
                        <span
                            v-if="entry.is_live"
                            class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                            data-testid="rectification-live"
                        >Em vigor</span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ entry.decided_by ?? 'Autor original não identificado' }} · {{ rectificationDateTime(entry.decided_at) }}
                    </p>
                    <p v-if="entry.reason" class="mt-1 text-xs"><span class="text-muted-foreground">Motivo:</span> {{ entry.reason }}</p>
                    <p v-if="entry.note" class="text-xs"><span class="text-muted-foreground">Nota:</span> {{ entry.note }}</p>
                    <p v-if="!entry.rectified" class="mt-1 text-xs text-muted-foreground">Classificação publicada originalmente.</p>
                </li>
            </ol>
        </DialogContent>
    </Dialog>
</template>
