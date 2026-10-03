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
                 em AppLayout), em TODAS as larguras, para o fim de qualquer página
                 rolar para fora de baixo dele. Abaixo de `sm`: 44px do botão + 16px
                 de margem + a área segura do dispositivo, mais folga. De `sm` para
                 cima: 32px do botão + 16px de margem (`h-14`), o que liberta a
                 ligação «Novidades» do rodapé. -->
            <div data-issue-reporter-clearance class="h-[calc(5rem+env(safe-area-inset-bottom))] shrink-0 sm:h-14 print:hidden" aria-hidden="true" />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
