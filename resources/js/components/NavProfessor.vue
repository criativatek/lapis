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

import type { SharedNavItem } from '@/types';

defineProps<{
    section: SharedNavSection;
}>();

const { isCurrentUrl, currentUrl } = useCurrentUrl();

/**
 * Whether this entry is the page being looked at.
 *
 * THE EXACT HREF IS NOT ENOUGH ANY MORE. «Turma» is one menu entry over three
 * historical routes — the class reading, the results grid and the synthesis —
 * and a teacher who followed a link into any of them should still see where
 * they are. The extra fragments come from the navigation config, so the
 * relationship between an entry and the paths it answers for is written down
 * once, next to the entry itself (§36).
 */
function isActive(item: SharedNavItem): boolean {
    if (item.href && isCurrentUrl(item.href)) {
        return true;
    }

    return item.match.some((fragment) => currentUrl.value.includes(fragment));
}

/**
 * What the sidebar says about an item when there is room to say more.
 *
 * NO SECOND VISUAL LINE. A description under every label would double the
 * sidebar's height for something a teacher reads once and then never again, so
 * it travels in the tooltip and in the accessible name instead — available when
 * wanted, invisible when not (§7).
 */
function hint(item: SharedNavItem): string {
    return item.description ? `${item.label} — ${item.description}` : item.label;
}
</script>

<template>
    <!-- Eight groups where there used to be three, so the vertical budget got
         tighter. The gap between groups comes down and the heading gets shorter
         and quieter — a heading is a signpost and must not compete with the
         links under it. Nothing was removed to buy the room (§32, §33). -->
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel
            v-if="section.label"
            class="h-6 text-[11px] font-medium tracking-wide text-sidebar-foreground/55 uppercase"
        >
            {{ section.label }}
        </SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in section.items" :key="item.key">
                <!-- Built pages link. Pages a later phase will deliver render as
                     an inert button labelled "em breve" — visible so the teacher
                     sees the full shape, but not a link to a 404. -->
                <SidebarMenuButton
                    v-if="item.href"
                    as-child
                    :is-active="isActive(item)"
                    :tooltip="hint(item)"
                >
                    <!-- The accessible name carries the description too, so a
                         screen reader hears what the page is for without the
                         sidebar having to show it (§14). `title` covers the
                         expanded sidebar, where the tooltip does not appear. -->
                    <Link
                        :href="item.href"
                        :title="item.description ?? undefined"
                        :aria-label="item.description ? hint(item) : undefined"
                    >
                        <component :is="navIcon(item.icon)" />
                        <span>{{ item.label }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuButton
                    v-else
                    :tooltip="`${hint(item)} — em breve`"
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
