<script setup lang="ts">
import ContextBar from '@/components/ContextBar.vue';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

// Declared so it is consumed rather than falling through onto <header>. The
// shell identifies location through the context bar, not breadcrumbs, matching
// the reference layout — pages may still pass them for future use.
withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    { breadcrumbs: () => [] },
);
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-border px-4 transition-[width,height] ease-linear"
    >
        <SidebarTrigger class="-ml-1" />
        <Separator orientation="vertical" class="mr-1 h-6" />
        <ContextBar />
    </header>
</template>
