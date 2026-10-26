<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { navIcon } from '@/lib/navIcons';
import type { SharedNavItem } from '@/types';

/**
 * A navegação inferior do telemóvel: os quatro sítios de todos os dias e o
 * menu inteiro.
 *
 * CONSTRUÍDA A PARTIR DO MENU QUE O SERVIDOR JÁ FILTROU. Cada posição procura
 * uma entrada por chave em `nav.sections` — se a organização não a tem
 * (Base sem `lessons`, por exemplo), a posição recua para a alternativa
 * («Calendário») ou desaparece. Nunca inventa um destino que a sidebar não
 * mostra: o mesmo `NavigationBuilder` decide os dois.
 *
 * «MENU» ABRE A SIDEBAR, não outra lista: tudo o que não cabe em quatro botões
 * continua onde sempre esteve, com os mesmos grupos e a mesma ordem.
 */
type Slot = { key: string; label: string; icon: LucideIcon; href: string; match: string[] };

const page = usePage();
const { setOpenMobile } = useSidebar();
const { currentUrl, isCurrentUrl } = useCurrentUrl();

const byKey = computed(() => {
    const items = new Map<string, SharedNavItem>();

    for (const section of page.props.nav.sections) {
        for (const item of section.items) {
            items.set(item.key, item);
        }
    }

    return items;
});

/** A primeira das chaves que o menu tem e já tem página; o rótulo curto é o da posição. */
function slot(candidates: Array<[key: string, label: string]>): Slot | null {
    for (const [key, label] of candidates) {
        const item = byKey.value.get(key);

        if (item?.href) {
            return { key: item.key, label, icon: navIcon(item.icon), href: item.href, match: item.match };
        }
    }

    return null;
}

const slots = computed(() =>
    [
        slot([['dashboard', 'Painel']]),
        slot([
            ['lessons', 'Aulas'],
            ['calendar', 'Calendário'],
        ]),
        slot([['classes', 'Turmas']]),
        slot([['assessments', 'Grelhas']]),
    ].filter((entry): entry is Slot => entry !== null),
);

function isActive(entry: Slot): boolean {
    return isCurrentUrl(entry.href) || entry.match.some((fragment) => currentUrl.value.includes(fragment));
}

const columns = computed(() => `repeat(${slots.value.length + 1}, minmax(0, 1fr))`);
</script>

<template>
    <nav
        v-if="slots.length > 0"
        class="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-background/95 pb-[env(safe-area-inset-bottom)] backdrop-blur supports-[backdrop-filter]:bg-background/85 md:hidden print:hidden"
        aria-label="Navegação rápida"
        data-mobile-nav
    >
        <ul class="grid h-14" :style="{ gridTemplateColumns: columns }">
            <li v-for="entry in slots" :key="entry.key" class="min-w-0">
                <Link
                    :href="entry.href"
                    class="flex h-14 flex-col items-center justify-center gap-0.5 px-1 text-[11px] leading-none font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                    :class="isActive(entry) ? 'text-primary' : 'text-muted-foreground hover:text-foreground'"
                    :aria-current="isActive(entry) ? 'page' : undefined"
                    :data-nav-key="entry.key"
                >
                    <component :is="entry.icon" class="size-5" aria-hidden="true" />
                    <span class="max-w-full truncate">{{ entry.label }}</span>
                </Link>
            </li>
            <li class="min-w-0">
                <button
                    type="button"
                    class="flex h-14 w-full flex-col items-center justify-center gap-0.5 px-1 text-[11px] leading-none font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                    data-testid="mobile-nav-menu"
                    @click="setOpenMobile(true)"
                >
                    <Menu class="size-5" aria-hidden="true" />
                    <span>Menu</span>
                </button>
            </li>
        </ul>
    </nav>
</template>
