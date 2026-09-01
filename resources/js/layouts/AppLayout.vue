<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ClosureBanners from '@/components/ClosureBanners.vue';
import IssueReporter from '@/components/IssueReporter.vue';
import PrivacyNotice from '@/components/PrivacyNotice.vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

const impersonating = computed(() => usePage().props.impersonating as { name?: string } | null);

function stopImpersonating(): void {
    router.post('/impersonate/stop');
}
</script>

<template>
    <div
        v-if="impersonating"
        class="flex items-center justify-center gap-3 bg-amber-500 px-4 py-1.5 text-center text-sm font-medium text-amber-950"
    >
        <span>A ver a app como <strong>{{ impersonating.name }}</strong> (impersonação de suporte)</span>
        <button type="button" class="rounded bg-amber-950/10 px-2 py-0.5 text-xs hover:bg-amber-950/20" @click="stopImpersonating">
            Terminar
        </button>
    </div>
    <PrivacyNotice />
    <ClosureBanners />
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
    <IssueReporter />
</template>
