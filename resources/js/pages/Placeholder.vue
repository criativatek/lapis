<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Hammer } from '@lucide/vue';

/**
 * The "em construção" page every teacher menu item routes to until the phase
 * that builds its real screen. Reachable and gated — arriving here means the
 * organization is entitled to the module; the page just doesn't exist yet.
 */
defineProps<{
    title: string;
    phase: number | null;
}>();
</script>

<template>
    <Head :title="title" />

    <div class="flex h-full flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
        <div
            class="flex size-14 items-center justify-center rounded-2xl bg-accent text-accent-foreground"
        >
            <Hammer class="size-7" />
        </div>
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
            <p class="text-muted-foreground">
                Este módulo faz parte do plano e será construído numa próxima fase.
            </p>
        </div>
        <span
            v-if="phase"
            class="rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground"
        >
            Previsto para a Fase {{ phase }}
        </span>
    </div>
</template>
