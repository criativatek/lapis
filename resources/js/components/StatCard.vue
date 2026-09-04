<script setup lang="ts">
import type { Component } from 'vue';
import type { SurfaceTone } from '@/lib/surfaces';
import { card } from '@/lib/surfaces';

/**
 * O cartão de estatística que o Dashboard copiava à mão.
 *
 * Assenta nas superfícies tintadas de `lib/surfaces.ts` — o «humano + cor» do
 * `.impeccable.md` dentro da app, em doses pálidas: a tinta é papel, nunca
 * estado. Profundidade por `card-soft` (duas sombras suaves), não por borda
 * dura; número em `tabular-nums` para as colunas não dançarem quando o valor
 * muda de largura.
 *
 * Para novas adopções, os tons são amber/mint/sky/plain — a paleta da marca.
 */
type Props = {
    label: string;
    value: string | number;
    icon?: Component;
    tone?: SurfaceTone;
    /** Uma linha pequena sob o rótulo — «2 por confirmar», nunca um zero solto. */
    hint?: string;
};

const props = withDefaults(defineProps<Props>(), {
    tone: 'plain',
});

const surface = card(props.tone);
</script>

<template>
    <div :class="[surface, 'card-soft flex items-center gap-3 p-4']">
        <span
            v-if="icon"
            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-background/70 text-foreground/70 dark:bg-background/40"
            aria-hidden="true"
        >
            <component :is="icon" class="size-5" />
        </span>

        <div class="min-w-0">
            <p class="text-2xl font-semibold tabular-nums">{{ value }}</p>
            <p class="truncate text-sm text-muted-foreground">{{ label }}</p>
            <p v-if="hint" class="truncate text-xs text-muted-foreground">{{ hint }}</p>
        </div>
    </div>
</template>
