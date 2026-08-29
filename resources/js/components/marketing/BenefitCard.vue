<script setup lang="ts">
import type { Component } from 'vue';

/**
 * One benefit: an icon in a coloured disc, a claim, a sentence. Rounded and
 * padded like a sticky note rather than a bordered box — the site is warm,
 * not a spreadsheet.
 */

type Tone = 'blue' | 'amber' | 'emerald';

withDefaults(
    defineProps<{
        icon: Component;
        title: string;
        body: string;
        tone?: Tone;
        /** On a blue band the card is white on blue; elsewhere it sits on white. */
        onBlue?: boolean;
    }>(),
    { tone: 'blue', onBlue: false },
);

const DISC: Record<Tone, string> = {
    blue: 'bg-blue-100 text-blue-700',
    amber: 'bg-amber-100 text-amber-700',
    emerald: 'bg-emerald-100 text-emerald-700',
};
</script>

<template>
    <div
        class="rounded-3xl p-6 sm:p-7"
        :class="
            onBlue
                ? 'bg-white/10 text-white'
                : 'bg-white shadow-sm ring-1 ring-black/5'
        "
    >
        <span
            aria-hidden="true"
            class="flex size-11 items-center justify-center rounded-2xl"
            :class="onBlue ? 'bg-white/15 text-white' : DISC[tone]"
        >
            <component :is="icon" class="size-5" />
        </span>
        <h3 class="mt-5 text-lg font-semibold tracking-tight text-balance">
            {{ title }}
        </h3>
        <p
            class="mt-2 text-sm leading-relaxed text-pretty"
            :class="onBlue ? 'text-blue-100' : 'text-muted-foreground'"
        >
            {{ body }}
        </p>
    </div>
</template>
