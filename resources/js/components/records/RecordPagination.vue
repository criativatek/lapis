<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/**
 * «Página 2 de 3 · 54 resultados» with the two neighbours. Built from the
 * server's URLs, never from the framework's link labels — those have no pt-PT
 * translation in this installation («pagination.previous»).
 */
defineProps<{
    currentPage: number;
    lastPage: number;
    total: number;
    prevUrl: string | null;
    nextUrl: string | null;
    noun: { one: string; many: string };
}>();
</script>

<template>
    <nav v-if="lastPage > 1 || total > 0" class="flex flex-wrap items-center justify-between gap-2 text-sm" aria-label="Paginação">
        <p class="text-muted-foreground tabular-nums">
            {{ total }} {{ total === 1 ? noun.one : noun.many }}<template v-if="lastPage > 1"> · Página {{ currentPage }} de {{ lastPage }}</template>
        </p>
        <div v-if="lastPage > 1" class="flex items-center gap-1">
            <Link
                v-if="prevUrl"
                :href="prevUrl"
                preserve-scroll
                class="inline-flex min-h-11 items-center rounded-md px-3 hover:bg-muted/40"
                rel="prev"
            >
                ‹ Anterior
            </Link>
            <span v-else class="inline-flex min-h-11 items-center px-3 text-muted-foreground/60" aria-disabled="true">‹ Anterior</span>
            <Link
                v-if="nextUrl"
                :href="nextUrl"
                preserve-scroll
                class="inline-flex min-h-11 items-center rounded-md px-3 hover:bg-muted/40"
                rel="next"
            >
                Seguinte ›
            </Link>
            <span v-else class="inline-flex min-h-11 items-center px-3 text-muted-foreground/60" aria-disabled="true">Seguinte ›</span>
        </div>
    </nav>
</template>
