<script setup lang="ts">
import { ChevronDown, Clock } from '@lucide/vue';
import type { Component } from 'vue';
import type { SurfaceTone } from '@/lib/surfaces';
import { card } from '@/lib/surfaces';

/**
 * Um dos três números do cabeçalho do painel. Assenta nas superfícies
 * tintadas (`card(tone)`) e no desenho do `StatCard` — ícone num quadrado
 * `bg-background/70`, número em `tabular-nums` — mas é compacto e pode ser um
 * `<button>` (o cartão «Pendentes» abre a lista). Dentro de um botão só há
 * `<span>`: é o único conteúdo de frase válido.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        value: string | number;
        label: string;
        icon?: Component;
        tone?: SurfaceTone;
        hint?: string | null;
        /** Aviso numa linha própria: relógio + texto âmbar (atrasos anteriores). */
        warning?: string | null;
        /** Verdadeiro: o cartão pede atenção; mostra o pequeno indicador âmbar junto ao título. */
        attention?: boolean;
        /** Verdadeiro: o cartão é um botão real. */
        clickable?: boolean;
        expanded?: boolean;
        controls?: string;
        /** Classe de altura mínima do grupo (só em colunas, onde os cartões se igualam; ver `heroMinHeightClass`). */
        minHeight?: string;
    }>(),
    { tone: 'plain', hint: null, warning: null, attention: false, clickable: false, expanded: false, minHeight: '' },
);

defineEmits<{ click: [] }>();

const surface = `${card(props.tone)} card-soft`;
</script>

<template>
    <component
        :is="clickable ? 'button' : 'div'"
        :type="clickable ? 'button' : undefined"
        :aria-expanded="clickable ? expanded : undefined"
        :aria-controls="clickable ? controls : undefined"
        :class="[
            surface,
            minHeight,
            'flex w-full items-center gap-3 px-4 py-2.5 text-left @min-[26.5rem]:py-3',
            clickable ? 'cursor-pointer transition-colors hover:brightness-[.98] focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:hover:brightness-110' : '',
        ]"
        @click="clickable && $emit('click')"
    >
        <span v-if="icon" class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-background/70 text-foreground/70 dark:bg-background/40" aria-hidden="true">
            <component :is="icon" class="size-[18px]" />
        </span>

        <span class="flex min-w-0 flex-1 flex-col gap-0.5 break-words">
            <span class="flex items-center gap-1.5">
                <span class="text-xs font-semibold tracking-[.08em] break-words text-muted-foreground uppercase">{{ title }}</span>
                <!-- O âmbar sinaliza o que pede atenção; a informação já está no número. -->
                <span v-if="attention" class="size-2 shrink-0 rounded-full bg-amber-600 dark:bg-amber-400" data-testid="hero-stat-attention" aria-hidden="true"></span>
            </span>
            <span class="flex flex-wrap items-baseline gap-x-1.5">
                <span class="text-2xl leading-7 font-semibold tabular-nums" data-testid="hero-stat-value">{{ value }}</span>
                <span class="min-w-0 text-sm break-words text-muted-foreground" data-testid="hero-stat-label">{{ label }}</span>
            </span>
            <span v-if="hint" class="text-xs text-muted-foreground" data-testid="hero-stat-hint">{{ hint }}</span>
            <span v-if="warning" class="flex items-start gap-1.5 text-xs leading-4 font-medium text-amber-800 dark:text-amber-200" data-testid="hero-stat-warning">
                <Clock class="mt-px size-3.5 shrink-0" aria-hidden="true" />{{ warning }}
            </span>
        </span>

        <ChevronDown v-if="clickable" class="size-4 shrink-0 text-muted-foreground transition-transform" :class="expanded ? 'rotate-180' : ''" aria-hidden="true" />
    </component>
</template>
