<script setup lang="ts">
/**
 * O formulário de um registo do caderno — o MESMO para criar e para editar.
 * Não sabe de pedidos: o texto vive na página, que o mantém exatamente como foi
 * escrito quando uma gravação falha.
 *
 * SÓ DE LEITURA ENQUANTO GRAVA: o sucesso fecha o formulário, e o que se
 * escrevesse entre «Guardar» e a resposta não ia no pedido — perdia-se sem
 * aviso. `readonly` e não `disabled`, para o foco ficar onde estava.
 */
import { onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{
    idPrefix: string;
    saving: boolean;
    titleError?: string;
    bodyError?: string;
    formError?: string;
}>();

const title = defineModel<string>('title', { required: true });
const body = defineModel<string>('body', { required: true });

const emit = defineEmits<{
    submit: [];
    cancel: [];
}>();

const root = ref<HTMLElement | null>(null);

onMounted(() => {
    root.value?.querySelector('textarea')?.focus();
});

function onBodyKeydown(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();

        if (!props.saving) {
            emit('submit');
        }
    }
}
</script>

<template>
    <form ref="root" class="space-y-4" novalidate @submit.prevent="emit('submit')">
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-title`">
                Título <span class="font-normal text-muted-foreground">(opcional)</span>
            </Label>
            <Input
                :id="`${idPrefix}-title`"
                v-model="title"
                type="text"
                maxlength="160"
                autocomplete="off"
                :readonly="saving"
                :aria-invalid="titleError ? true : undefined"
            />
            <InputError :message="titleError" />
        </div>

        <div class="grid gap-2">
            <Label :for="`${idPrefix}-body`">Registo</Label>
            <Textarea
                :id="`${idPrefix}-body`"
                v-model="body"
                rows="6"
                class="min-h-32"
                :readonly="saving"
                :aria-invalid="bodyError ? true : undefined"
                @keydown="onBodyKeydown"
            />
            <InputError :message="bodyError" />
        </div>

        <InputError :message="formError" />

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button type="button" variant="outline" class="min-h-11 w-full sm:w-auto" :disabled="saving" @click="emit('cancel')">
                Cancelar
            </Button>
            <Button type="submit" class="min-h-11 w-full sm:w-auto" :disabled="saving">
                <Spinner v-if="saving" aria-hidden="true" />
                {{ saving ? 'A guardar…' : 'Guardar' }}
            </Button>
        </div>
    </form>
</template>
