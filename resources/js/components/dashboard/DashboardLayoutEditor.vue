<script setup lang="ts">
import { X } from '@lucide/vue';
import { nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { BlockId } from '@/lib/dashboardLayout';
import { BLOCK_LABEL } from '@/lib/dashboardLayout';

/**
 * «Organizar painel»: o diálogo da aplicação (que já trata do foco contido, do
 * Escape, do fundo inerte e do bloqueio do scroll) com a lista numerada dos
 * blocos e «Subir»/«Descer». A lista É a pré-visualização: a página real, por
 * trás, mostra a ordem em direto; só «Guardar» persiste. «Cancelar», Escape e
 * «Fechar» são o mesmo gesto: descartar.
 */
const props = defineProps<{
    open: boolean;
    order: BlockId[];
    announcement: string;
}>();

const emit = defineEmits<{
    move: [id: BlockId, delta: -1 | 1];
    save: [];
    reset: [];
    cancel: [];
    /** O diálogo fechou: quem o abriu recebe o foco de volta. */
    closed: [];
}>();

const list = ref<HTMLElement | null>(null);

// O foco inicial vai para o primeiro botão utilizável da lista, não para o «Fechar».
function focusFirstControl(event: Event): void {
    event.preventDefault();
    list.value?.querySelector<HTMLButtonElement>('button:not([disabled])')?.focus();
}

// Depois de mover o foco fica no mesmo botão, ou no oposto se este ficou desativado no fim da lista.
async function move(id: BlockId, delta: -1 | 1): Promise<void> {
    emit('move', id, delta);
    await nextTick();

    const same = list.value?.querySelector<HTMLButtonElement>(`[data-move="${id}:${delta}"]`);
    const other = list.value?.querySelector<HTMLButtonElement>(`[data-move="${id}:${-delta}"]`);

    (same && !same.disabled ? same : other)?.focus();
}

function onCloseAutoFocus(event: Event): void {
    event.preventDefault();
    emit('closed');
}
</script>

<template>
    <Dialog :open="props.open" @update:open="(value: boolean) => !value && emit('cancel')">
        <DialogContent
            class="max-h-[calc(100dvh-2rem)] grid-rows-[auto_minmax(0,1fr)_auto] gap-0 p-0 sm:max-w-md"
            overlay-class="bg-black/50 sm:bg-black/15"
            :show-close-button="false"
            data-testid="layout-editor"
            @open-auto-focus="focusFirstControl"
            @close-auto-focus="onCloseAutoFocus"
            @pointer-down-outside.prevent
        >
            <DialogHeader class="relative gap-1 px-5 pt-5 pr-14 pb-3 text-left">
                <DialogTitle class="text-base leading-6">Organizar painel</DialogTitle>
                <DialogDescription>Escolha a ordem dos blocos do seu painel.</DialogDescription>

                <DialogClose as-child>
                    <Button variant="ghost" size="icon" class="absolute top-2 right-2 size-11" aria-label="Fechar" data-testid="layout-close">
                        <X class="size-4" aria-hidden="true" />
                    </Button>
                </DialogClose>
            </DialogHeader>

            <div class="min-h-0 overflow-y-auto px-5">
                <ol ref="list" class="divide-y divide-border border-y border-border" aria-label="Ordem dos blocos">
                    <li v-for="(id, index) in props.order" :key="id" class="flex items-center justify-between gap-3 py-1.5">
                        <span class="min-w-0 text-sm font-medium"><span class="mr-2 text-muted-foreground tabular-nums">{{ index + 1 }}.</span>{{ BLOCK_LABEL[id] }}</span>
                        <span class="flex shrink-0 gap-1.5">
                            <Button variant="outline" size="sm" class="min-h-11" :disabled="index === 0" :aria-label="`Subir ${BLOCK_LABEL[id]}`" :data-move="`${id}:-1`" @click="move(id, -1)">Subir</Button>
                            <Button variant="outline" size="sm" class="min-h-11" :disabled="index === props.order.length - 1" :aria-label="`Descer ${BLOCK_LABEL[id]}`" :data-move="`${id}:1`" @click="move(id, 1)">Descer</Button>
                        </span>
                    </li>
                </ol>

                <p class="sr-only" role="status" aria-live="polite" data-testid="layout-live">{{ props.announcement }}</p>
            </div>

            <DialogFooter class="grid grid-cols-1 gap-2 px-5 py-4 sm:flex sm:flex-row sm:justify-start">
                <Button class="min-h-11" data-testid="layout-save" @click="emit('save')">Guardar</Button>
                <Button variant="outline" class="min-h-11" data-testid="layout-reset" @click="emit('reset')">Repor disposição original</Button>
                <Button variant="ghost" class="min-h-11" data-testid="layout-cancel" @click="emit('cancel')">Cancelar</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
