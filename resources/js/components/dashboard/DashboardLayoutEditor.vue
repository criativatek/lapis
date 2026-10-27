<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { BlockId } from '@/lib/dashboardLayout';
import { BLOCK_LABEL } from '@/lib/dashboardLayout';

/**
 * «Organizar painel»: a lista dos blocos com «Subir» e «Descer». A página por
 * cima mostra a pré-visualização em direto; só «Guardar» persiste. Sem arrastar:
 * botões são teclado, leitor de ecrã e toque ao mesmo tempo.
 */
const props = defineProps<{
    order: BlockId[];
    announcement: string;
}>();

const emit = defineEmits<{
    move: [id: BlockId, delta: -1 | 1];
    save: [];
    reset: [];
    cancel: [];
}>();

const root = ref<HTMLElement | null>(null);

// O foco vai para o painel ao abrir (o botão do rodapé fica longe da vista).
onMounted(() => {
    root.value?.scrollIntoView({ block: 'center' });
    root.value?.focus({ preventScroll: true });
});

// Depois de mover, o foco fica no mesmo botão — ou no oposto se este ficou desativado no fim da lista.
async function move(id: BlockId, delta: -1 | 1): Promise<void> {
    emit('move', id, delta);
    await nextTick();

    const same = root.value?.querySelector<HTMLButtonElement>(`[data-move="${id}:${delta}"]`);
    const other = root.value?.querySelector<HTMLButtonElement>(`[data-move="${id}:${-delta}"]`);

    (same && !same.disabled ? same : other)?.focus();
}
</script>

<template>
    <section
        id="organizar-painel"
        ref="root"
        tabindex="-1"
        class="rounded-2xl border border-border bg-card p-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:px-5"
        aria-labelledby="organizar-painel-titulo"
        data-testid="layout-editor"
        @keydown.esc="emit('cancel')"
    >
        <h2 id="organizar-painel-titulo" class="text-[15px] leading-5 font-semibold">Organizar painel</h2>
        <p class="mt-1 text-sm text-muted-foreground">Escolha a ordem dos blocos; vê-se logo na página. O cabeçalho e a régua «Hoje» não mudam.</p>

        <ol class="mt-3 divide-y divide-border border-y border-border" aria-label="Ordem dos blocos">
            <li v-for="(id, index) in props.order" :key="id" class="flex items-center justify-between gap-3 py-1.5">
                <span class="min-w-0 text-sm font-medium"><span class="mr-2 text-muted-foreground tabular-nums">{{ index + 1 }}.</span>{{ BLOCK_LABEL[id] }}</span>
                <span class="flex shrink-0 gap-1.5">
                    <Button variant="outline" size="sm" class="min-h-11 sm:min-h-8" :disabled="index === 0" :aria-label="`Subir ${BLOCK_LABEL[id]}`" :data-move="`${id}:-1`" @click="move(id, -1)">Subir</Button>
                    <Button variant="outline" size="sm" class="min-h-11 sm:min-h-8" :disabled="index === props.order.length - 1" :aria-label="`Descer ${BLOCK_LABEL[id]}`" :data-move="`${id}:1`" @click="move(id, 1)">Descer</Button>
                </span>
            </li>
        </ol>

        <p class="sr-only" role="status" aria-live="polite" data-testid="layout-live">{{ props.announcement }}</p>

        <div class="mt-3 flex flex-wrap gap-2">
            <Button size="sm" class="min-h-11 sm:min-h-8" data-testid="layout-save" @click="emit('save')">Guardar</Button>
            <Button variant="outline" size="sm" class="min-h-11 sm:min-h-8" data-testid="layout-reset" @click="emit('reset')">Repor disposição original</Button>
            <Button variant="ghost" size="sm" class="min-h-11 sm:min-h-8" data-testid="layout-cancel" @click="emit('cancel')">Cancelar</Button>
        </div>
    </section>
</template>
