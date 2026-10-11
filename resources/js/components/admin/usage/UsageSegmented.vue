<script setup lang="ts" generic="T extends string">
/**
 * Um controlo segmentado: poucas opções mutuamente exclusivas, todas visíveis.
 * Botões com `aria-pressed` em vez de rádios — funciona igual com teclado e
 * leitor de ecrã, e dá um alvo de 44px no telemóvel.
 */
defineProps<{ options: { key: T; label: string }[]; label: string }>();

const model = defineModel<T>({ required: true });
</script>

<template>
    <div role="group" :aria-label="label" class="inline-flex flex-wrap overflow-hidden rounded-md border border-border bg-background">
        <button
            v-for="option in options"
            :key="option.key"
            type="button"
            :aria-pressed="model === option.key"
            class="min-h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:min-h-9"
            :class="model === option.key ? 'bg-primary font-medium text-primary-foreground' : 'text-foreground hover:bg-muted'"
            @click="model = option.key"
        >
            {{ option.label }}
        </button>
    </div>
</template>
