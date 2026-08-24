<script setup lang="ts">
import {
    Building2,
    CalendarRange,
    ClipboardList,
    Footprints,
    HeartHandshake,
    LayoutGrid,
    NotebookPen,
    PenLine,
    RefreshCw,
    Send,
    SlidersHorizontal,
    TrendingUp,
    UserCheck,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ClassificationPreview from './ClassificationPreview.vue';
import type { TourRegion } from './tourRegions';

/**
 * The LÁPIS shell, drawn at rest: the sidebar the teacher navigates by, the
 * context selectors in the header, and one class open underneath.
 *
 * It is a MOCK and not the real AppLayout — the real one needs a resolved
 * tenant, an entitlement check and a signed-in user, none of which a public
 * page has. What it copies faithfully is the structure and the sidebar tokens,
 * so the picture is the application and not an artist's impression of it.
 *
 * The regions carry no interactivity of their own: the accessible way through
 * this tour is the list beside it, and a button here would nest inside the
 * table it highlights.
 */

defineProps<{ active: TourRegion | null }>();

const emit = defineEmits<{
    (event: 'enter', region: TourRegion | null): void;
}>();

type MenuGroup = {
    label: string | null;
    items: { icon: Component; label: string }[];
};

/** The real side menu, in the real order (config/navigation.php). */
const menu: readonly MenuGroup[] = [
    {
        label: null,
        items: [{ icon: LayoutGrid, label: 'Painel do Professor' }],
    },
    {
        label: 'Turmas e alunos',
        items: [{ icon: Users, label: 'Turmas' }],
    },
    {
        label: 'Avaliação',
        items: [
            { icon: ClipboardList, label: 'Elementos de Avaliação' },
            { icon: PenLine, label: 'Grelhas de correção' },
            { icon: UserCheck, label: 'Autoavaliações' },
        ],
    },
    {
        label: 'Acompanhamento',
        items: [
            { icon: TrendingUp, label: 'Turma' },
            { icon: Footprints, label: 'Aluno' },
        ],
    },
    {
        label: 'Ação pedagógica',
        items: [
            { icon: HeartHandshake, label: 'Estratégias e Medidas' },
            { icon: NotebookPen, label: 'Registos' },
        ],
    },
    {
        label: 'Configuração',
        items: [{ icon: SlidersHorizontal, label: 'Perfis de Avaliação' }],
    },
];

/** The header's scope selectors, with the values a working teacher would see. */
const context = [
    { label: 'Ano letivo', value: '2026/27' },
    { label: 'Disciplina', value: 'Matemática' },
    { label: 'Ano', value: '9.º' },
    { label: 'Turma', value: '9.º B' },
    { label: 'Período', value: '2.º' },
] as const;

/** Lit when it is the region being explained, dimmed when another one is. */
function tone(region: TourRegion, active: TourRegion | null): string {
    if (active === null) {
        return 'ring-0';
    }

    return active === region
        ? 'ring-2 ring-(--brand-amber) ring-offset-2 ring-offset-background z-10'
        : 'opacity-45';
}
</script>

<template>
    <div
        class="flex overflow-hidden rounded-xl border border-border bg-background text-left"
        @mouseleave="emit('enter', null)"
    >
        <!-- The sidebar, in the application's own navy. -->
        <aside
            class="hidden w-52 shrink-0 flex-col bg-sidebar p-2.5 text-sidebar-foreground sm:flex"
        >
            <div class="flex items-center gap-2 px-1.5 py-2">
                <span
                    class="flex size-7 items-center justify-center rounded-md bg-(--brand-amber)/15 text-(--brand-amber)"
                >
                    <AppLogoIcon class="size-4" />
                </span>
                <span class="text-[13px] font-semibold text-white">LÁPIS</span>
            </div>

            <nav
                class="mt-2 flex-1 space-y-2 rounded-lg p-1.5 transition-all duration-300"
                :class="tone('menu', active)"
                @mouseenter="emit('enter', 'menu')"
            >
                <div v-for="(group, index) in menu" :key="index">
                    <p
                        v-if="group.label"
                        class="px-1.5 pb-1 text-[9px] font-semibold tracking-[0.12em] text-sidebar-foreground/45 uppercase"
                    >
                        {{ group.label }}
                    </p>
                    <p
                        v-for="item in group.items"
                        :key="item.label"
                        class="flex items-center gap-2 rounded-md px-1.5 py-1 text-[11px]"
                        :class="
                            item.label === 'Turmas'
                                ? 'bg-sidebar-accent text-white'
                                : undefined
                        "
                    >
                        <component
                            :is="item.icon"
                            aria-hidden="true"
                            class="size-3.5 shrink-0 opacity-70"
                        />
                        <span class="truncate">{{ item.label }}</span>
                    </p>
                </div>
            </nav>

            <div
                class="mt-2 flex items-center gap-2 rounded-lg p-1.5 transition-all duration-300"
                :class="tone('account', active)"
                @mouseenter="emit('enter', 'account')"
            >
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-full bg-sidebar-accent text-[10px] font-medium text-white"
                >
                    PA
                </span>
                <span class="min-w-0 flex-1">
                    <span
                        class="block truncate text-[11px] font-medium text-white"
                        >Pedro Alves</span
                    >
                    <span
                        class="flex items-center gap-1 truncate text-[10px] text-sidebar-foreground/60"
                    >
                        <Building2 aria-hidden="true" class="size-2.5" />
                        Agrupamento de Gaia
                    </span>
                </span>
            </div>
        </aside>

        <!-- The working area. -->
        <div class="min-w-0 flex-1 bg-background">
            <div
                class="flex flex-wrap items-center gap-1.5 border-b border-border p-2 transition-all duration-300"
                :class="tone('context', active)"
                @mouseenter="emit('enter', 'context')"
            >
                <span
                    v-for="chip in context"
                    :key="chip.label"
                    class="flex items-center gap-1.5 rounded-md border border-border bg-card px-2 py-1 text-[11px]"
                >
                    <CalendarRange
                        v-if="chip.label === 'Ano letivo'"
                        aria-hidden="true"
                        class="size-3 text-muted-foreground"
                    />
                    <span class="text-muted-foreground">{{ chip.label }}</span>
                    <span class="font-medium">{{ chip.value }}</span>
                </span>
            </div>

            <div class="p-3 sm:p-4">
                <div
                    class="flex flex-wrap items-center gap-2 rounded-lg p-1.5 transition-all duration-300"
                    :class="tone('actions', active)"
                    @mouseenter="emit('enter', 'actions')"
                >
                    <p class="text-xs text-muted-foreground">
                        O sistema propõe; o professor confirma.
                        <span class="font-medium text-foreground"
                            >1 por confirmar.</span
                        >
                    </p>
                    <span class="ml-auto flex gap-1.5">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-md border border-emerald-600 px-2 py-1 text-[11px] font-medium text-emerald-700 dark:text-emerald-400"
                        >
                            <Send aria-hidden="true" class="size-3" />
                            Publicar confirmadas
                        </span>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-md bg-primary px-2 py-1 text-[11px] font-medium text-primary-foreground"
                        >
                            <RefreshCw aria-hidden="true" class="size-3" />
                            Gerar propostas
                        </span>
                    </span>
                </div>

                <div
                    class="mt-2 overflow-hidden rounded-xl border border-border transition-all duration-300"
                    :class="tone('decision', active)"
                    @mouseenter="emit('enter', 'decision')"
                >
                    <ClassificationPreview />
                </div>
            </div>
        </div>
    </div>
</template>
