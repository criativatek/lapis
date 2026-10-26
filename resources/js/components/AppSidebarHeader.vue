<script setup lang="ts">
import ContextBar from '@/components/ContextBar.vue';
import HeaderActions from '@/components/HeaderActions.vue';
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
    <!-- 56px: a barra diz o contexto (ano letivo, turma) e dá três atalhos —
         pesquisa nos sumários, suporte, conta. No telemóvel o gatilho da
         sidebar e os atalhos têm 44px; de `md` para cima encolhem. -->
    <header
        class="flex h-14 shrink-0 items-center gap-2 border-b border-border px-3 transition-[width,height] ease-linear sm:px-4"
        data-app-header
    >
        <SidebarTrigger class="-ml-1 h-11 w-11 md:h-7 md:w-7" />
        <Separator orientation="vertical" class="mr-1 hidden h-6 md:block" />
        <ContextBar />
        <HeaderActions />
    </header>
</template>
