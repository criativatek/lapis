<script setup lang="ts">
import AppBanners from '@/components/AppBanners.vue';
import AppContent from '@/components/AppContent.vue';
import AppFooter from '@/components/AppFooter.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import MobileNav from '@/components/MobileNav.vue';
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
            <!-- Reserva para o que flutua por cima do fim da página, em TODAS
                 as larguras. Abaixo de `md`: a navegação inferior (56px + área
                 segura) e, por cima dela, o botão «Reportar problema»
                 (IssueReporter, montado em AppLayout: 44px + 16px de margem),
                 com folga. De `md` para cima só o botão: 36px + 16px (`h-14`),
                 o que liberta a ligação «Novidades» do rodapé. -->
            <div data-issue-reporter-clearance class="h-[calc(8.5rem+env(safe-area-inset-bottom))] shrink-0 md:h-14 print:hidden" aria-hidden="true" />
        </AppContent>
        <!-- Dentro do AppShell, porque «Menu» abre a sidebar pelo contexto do
             SidebarProvider; fixa ao fundo, por isso o sítio no DOM não conta. -->
        <MobileNav />
        <Toaster />
    </AppShell>
</template>
