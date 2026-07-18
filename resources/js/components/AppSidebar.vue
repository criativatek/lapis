<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavProfessor from '@/components/NavProfessor.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';

// The menu is built and entitlement-filtered on the server (NavigationBuilder)
// and shared via Inertia, so the sidebar only renders what this organization
// is allowed to see. A footer section (Configurações) is styled like any other.
const page = usePage();
const nav = computed(() => page.props.nav);
const footerSection = computed(() => ({ label: null, items: nav.value.footer }));
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavProfessor
                v-for="(section, index) in nav.sections"
                :key="index"
                :section="section"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavProfessor
                v-if="footerSection.items.length"
                :section="footerSection"
            />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
