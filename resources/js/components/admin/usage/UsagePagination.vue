<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { UsagePaginated } from '@/types/usage';

defineProps<{ links: UsagePaginated<unknown>['links'] }>();
</script>

<template>
    <nav v-if="links.length > 3" aria-label="Paginação" class="flex flex-wrap gap-1">
        <template v-for="(link, index) in links" :key="index">
            <!-- O rótulo é do paginador («&laquo; Anterior»), traz entidades: v-html
                 num elemento nativo, nunca no componente (que substituiria a âncora). -->
            <Link
                v-if="link.url"
                :href="link.url"
                class="flex min-h-11 min-w-11 items-center justify-center rounded-md border border-border px-3 text-sm md:min-h-9 md:min-w-9"
                :class="link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted/40'"
                preserve-scroll
            >
                <span v-html="link.label" />
            </Link>
            <span
                v-else
                class="flex min-h-11 min-w-11 items-center justify-center rounded-md border border-border px-3 text-sm text-muted-foreground opacity-50 md:min-h-9 md:min-w-9"
                v-html="link.label"
            />
        </template>
    </nav>
</template>
