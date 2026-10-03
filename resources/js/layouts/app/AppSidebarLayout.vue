<script setup lang="ts">
import AppBanners from '@/components/AppBanners.vue';
import AppContent from '@/components/AppContent.vue';
import AppFooter from '@/components/AppFooter.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppBanners />
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div class="flex flex-1 flex-col">
                <slot />
            </div>
            <AppFooter />
            <!-- Reserva para o botão flutuante «Reportar problema» (IssueReporter, montado
                 em AppLayout): 44px do botão + 16px de margem + a área segura do
                 dispositivo, mais folga. Só abaixo de `sm`, onde o botão é fixo sobre
                 o conteúdo; assim o fim de qualquer página rola para fora de baixo dele. -->
            <div data-issue-reporter-clearance class="h-[calc(5rem+env(safe-area-inset-bottom))] shrink-0 sm:hidden print:hidden" aria-hidden="true" />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
