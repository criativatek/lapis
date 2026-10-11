<script setup lang="ts">
/**
 * «Pesquisar nos sumários…» — o campo, e só o campo.
 *
 * Enter pesquisa logo; Escape e o botão «×» limpam (e a página volta ao
 * calendário). O resto — a pausa durante a escrita, o URL, o pedido — é da
 * página: aqui não se decide nada sobre a pesquisa.
 */
import { Search, X } from '@lucide/vue';
import { ref } from 'vue';
import { Input } from '@/components/ui/input';

defineProps<{
    modelValue: string;
    /** Uma indicação curta por baixo do campo (p. ex. «Escreve pelo menos dois caracteres.»). */
    hint?: string | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
    submit: [];
    clear: [];
}>();

const field = ref<InstanceType<typeof Input> | null>(null);

function focus(): void {
    (field.value?.$el as HTMLInputElement | undefined)?.focus();
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        event.preventDefault();
        emit('submit');
    } else if (event.key === 'Escape') {
        event.preventDefault();
        emit('clear');
    }
}

defineExpose({ focus });
</script>

<template>
    <div class="min-w-0" role="search">
        <label for="lessons-summary-search" class="sr-only"
            >Pesquisar nos sumários</label
        >
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                id="lessons-summary-search"
                ref="field"
                type="search"
                :model-value="modelValue"
                placeholder="Pesquisar nos sumários…"
                autocomplete="off"
                enterkeyhint="search"
                spellcheck="false"
                maxlength="200"
                aria-describedby="lessons-summary-search-hint"
                class="h-11 bg-card pr-11 pl-9 sm:h-9 sm:pr-10 [&::-webkit-search-cancel-button]:appearance-none"
                data-testid="summary-search-input"
                @update:model-value="
                    (value) => emit('update:modelValue', String(value))
                "
                @keydown="onKeydown"
            />
            <button
                v-if="modelValue !== ''"
                type="button"
                class="absolute top-1/2 right-0 inline-flex size-11 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:size-9"
                aria-label="Limpar a pesquisa e voltar ao calendário"
                data-testid="summary-search-clear"
                @click="emit('clear')"
            >
                <X class="size-4" aria-hidden="true" />
            </button>
        </div>
        <p
            id="lessons-summary-search-hint"
            :class="hint ? 'mt-1 text-[13px] text-muted-foreground' : 'sr-only'"
            aria-live="polite"
        >
            {{
                hint ??
                'Procura a palavra ou a expressão em todo o ano letivo, sem distinguir maiúsculas nem acentos.'
            }}
        </p>
    </div>
</template>
