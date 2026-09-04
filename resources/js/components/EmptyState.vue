<script setup lang="ts">
import type { Component } from 'vue';

/**
 * O estado vazio que 48 páginas escreviam à mão.
 *
 * A regra do `.impeccable.md` que este componente torna estrutural: **estados
 * vazios são vazios, nunca zero** — dizem o que ainda não existe e qual é o
 * próximo passo, em vez de mostrarem um número a fingir que é conteúdo. Daí o
 * slot `#action`: um vazio sem saída é um beco.
 */
type Props = {
    title: string;
    description?: string;
    icon?: Component;
};

defineProps<Props>();
</script>

<template>
    <div class="rounded-2xl border border-dashed border-border p-10 text-center">
        <span
            v-if="icon"
            class="mx-auto mb-3 flex size-12 items-center justify-center rounded-xl bg-accent text-accent-foreground"
            aria-hidden="true"
        >
            <component :is="icon" class="size-6" />
        </span>

        <p class="text-sm font-medium">{{ title }}</p>
        <p v-if="description" class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
            {{ description }}
        </p>

        <div v-if="$slots.action" class="mt-4 flex justify-center">
            <slot name="action" />
        </div>
    </div>
</template>
