<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { MessageSquarePlus, MessageSquareText } from '@lucide/vue';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

/**
 * O código de uma pergunta/critério (Q3) com o comentário privado do professor:
 * um ícone sempre visível (`+` discreto quando não há comentário, destacado
 * quando há), o texto ao passar o rato e ao
 * receber foco do teclado (tooltip), e — só quem pode editar — um editor que
 * grava com `PUT …/items/notes`, como o «Conteúdo» grava com `…/items/labels`.
 * Sem comentário e sem edição, é só o código.
 */
const props = defineProps<{
    itemId: number;
    code: string;
    note: string | null;
    url: string;
    editable: boolean;
}>();

const emit = defineEmits<{ saved: [value: string | null] }>();

const current = ref<string | null>(props.note);
const draft = ref(props.note ?? '');
const open = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);

watch(() => props.note, (value) => {
    current.value = value;
});

function openEditor(): void {
    draft.value = current.value ?? '';
    error.value = null;
    open.value = true;
}

function save(value: string): void {
    if (busy.value) {
        return;
    }

    const trimmed = value.trim();

    busy.value = true;
    error.value = null;

    router.put(props.url, { notes: { [props.itemId]: trimmed } }, {
        preserveScroll: true,
        preserveState: true,
        only: [],
        onSuccess: () => {
            current.value = trimmed === '' ? null : trimmed;
            open.value = false;
            emit('saved', current.value);
        },
        onError: (errors) => {
            error.value = Object.values(errors)[0] ?? 'Não foi possível guardar o comentário.';
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    v-if="editable || current"
                    type="button"
                    class="inline-flex items-center gap-1 rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    :class="current ? 'underline decoration-dotted decoration-2 underline-offset-4' : 'hover:underline hover:decoration-dotted'"
                    :title="current ? undefined : `Adicionar comentário a ${code}`"
                    :aria-label="current ? `${code} — com comentário` : `Adicionar comentário a ${code}`"
                    data-testid="criterion-note-trigger"
                    @click="editable ? openEditor() : undefined"
                >
                    <span>{{ code }}</span>
                    <MessageSquareText v-if="current" class="size-3.5 text-primary" aria-hidden="true" data-testid="criterion-note-icon-filled" />
                    <MessageSquarePlus v-else class="size-3.5 text-muted-foreground" aria-hidden="true" data-testid="criterion-note-icon-add" />
                </button>
                <span v-else>{{ code }}</span>
            </TooltipTrigger>
            <TooltipContent v-if="current && !open" class="max-w-xs text-left whitespace-pre-line" data-testid="criterion-note-tooltip">
                {{ current }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>

    <Dialog v-if="editable" v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Comentário de {{ code }}</DialogTitle>
                <DialogDescription>
                    Só o professor vê este comentário. Fica na grelha e acompanha-a quando a copia para outra.
                </DialogDescription>
            </DialogHeader>
            <Textarea v-model="draft" rows="5" maxlength="2000" :aria-label="`Comentário de ${code}`" data-testid="criterion-note-input" />
            <InputError :message="error ?? undefined" />
            <DialogFooter class="gap-2 sm:justify-between">
                <Button
                    v-if="current"
                    type="button"
                    variant="outline"
                    :disabled="busy"
                    data-testid="criterion-note-delete"
                    @click="save('')"
                >
                    Apagar comentário
                </Button>
                <Button type="button" :disabled="busy" data-testid="criterion-note-save" @click="save(draft)">Guardar</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
