<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { navIcon } from '@/lib/navIcons';
import type { SharedNavSection } from '@/types';

defineProps<{
    section: SharedNavSection;
}>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel v-if="section.label">{{ section.label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in section.items" :key="item.key">
                <!-- Built pages link. Pages a later phase will deliver render as
                     an inert button labelled "em breve" — visible so the teacher
                     sees the full shape, but not a link to a 404. -->
                <SidebarMenuButton
                    v-if="item.href"
                    as-child
                    :is-active="isCurrentUrl(item.href)"
                    :tooltip="item.label"
                >
                    <Link :href="item.href">
                        <component :is="navIcon(item.icon)" />
                        <span>{{ item.label }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuButton
                    v-else
                    :tooltip="`${item.label} — em breve`"
                    class="cursor-default opacity-55"
                    aria-disabled="true"
                >
                    <component :is="navIcon(item.icon)" />
                    <span>{{ item.label }}</span>
                    <span
                        class="ml-auto rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground group-data-[collapsible=icon]:hidden"
                        >em breve</span
                    >
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
