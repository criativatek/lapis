<script setup lang="ts">
import IssueReporter from '@/components/IssueReporter.vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <!-- As faixas do topo vivem DENTRO do AppSidebarLayout (AppBanners) — a
         sidebar é fixa e uma faixa montada aqui fora ficava-lhe por baixo
         (SUP-8Y3Q5Y). -->
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
    <IssueReporter />
</template>
