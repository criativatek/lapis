<script setup lang="ts">
/**
 * NAVEGAÇÃO FIXA E DISCRETA — atalhos para os dias da semana (0.158.0).
 *
 * Uma só barra fina, colada ao topo enquanto se lê, com um botão por dia que
 * leva ao dia e diz qual está à vista. Não tapa conteúdo: os títulos dos dias
 * guardam por baixo dela a margem de rolagem (`scroll-mt-24`). No telemóvel é
 * uma fila de alvos de 44px e nada mais — a área útil continua a ser dos
 * sumários.
 *
 * Quando se chega à semana a partir do Horário («Ver sumário completo»), a
 * barra oferece também o regresso ao horário.
 */
import { ArrowLeft } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

export type DayNavItem = {
    /** O `id` do elemento para onde o atalho leva. */
    target: string;
    label: string;
    sub: string;
    today?: boolean;
    description: string;
};

const props = withDefaults(
    defineProps<{
        items: DayNavItem[];
        backLabel?: string | null;
        ariaLabel?: string;
    }>(),
    {
        backLabel: null,
        ariaLabel: 'Atalhos para os dias',
    },
);

const emit = defineEmits<{ back: [] }>();

const active = ref<string | null>(null);
const bar = ref<HTMLElement | null>(null);
let frame = 0;

function updateActive(): void {
    frame = 0;
    const offset = (bar.value?.getBoundingClientRect().bottom ?? 0) + 24;
    let current: string | null = props.items[0]?.target ?? null;

    for (const item of props.items) {
        const element = document.getElementById(item.target);

        if (element && element.getBoundingClientRect().top <= offset) {
            current = item.target;
        }
    }

    active.value = current;
}

function onScroll(): void {
    if (frame === 0) {
        frame = window.requestAnimationFrame(updateActive);
    }
}

onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true });
    updateActive();
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', onScroll);

    if (frame !== 0) {
        window.cancelAnimationFrame(frame);
    }
});

watch(
    () => props.items,
    () => window.requestAnimationFrame(updateActive),
);

function jump(target: string): void {
    const element = document.getElementById(target);

    if (element === null) {
        return;
    }

    const reduced =
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ??
        false;
    element.scrollIntoView({
        behavior: reduced ? 'auto' : 'smooth',
        block: 'start',
    });
    element.focus({ preventScroll: true });
    active.value = target;
}
</script>

<template>
    <nav
        v-if="items.length > 1 || backLabel"
        ref="bar"
        :aria-label="ariaLabel"
        data-testid="lesson-day-nav"
        class="sticky top-0 z-20 -mx-4 border-b bg-background/95 px-4 py-1.5 backdrop-blur supports-[backdrop-filter]:bg-background/85 sm:-mx-6 sm:px-6 print:hidden"
    >
        <div class="flex items-center gap-1.5">
            <button
                v-if="backLabel"
                type="button"
                class="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-md border px-2.5 text-sm font-medium outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-h-8"
                data-testid="back-to-timetable"
                @click="emit('back')"
            >
                <ArrowLeft class="size-4" aria-hidden="true" />{{ backLabel }}
            </button>
            <ul
                class="grid min-w-0 flex-1 auto-cols-fr grid-flow-col gap-1 sm:flex sm:flex-wrap sm:gap-1.5"
            >
                <li v-for="item in items" :key="item.target" class="min-w-0">
                    <button
                        type="button"
                        :aria-current="
                            active === item.target ? 'location' : undefined
                        "
                        :aria-label="item.description"
                        :class="[
                            'flex min-h-11 w-full flex-col items-center justify-center rounded-md px-1.5 text-xs leading-4 font-semibold outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-h-8 sm:flex-row sm:gap-1.5 sm:px-2.5 sm:text-[13px]',
                            active === item.target
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-accent',
                        ]"
                        @click="jump(item.target)"
                    >
                        <span class="capitalize">{{ item.label }}</span>
                        <span
                            :class="[
                                'tabular-nums',
                                active === item.target
                                    ? ''
                                    : 'text-muted-foreground',
                            ]"
                            >{{ item.sub }}</span
                        >
                        <span
                            v-if="item.today"
                            :class="[
                                'hidden rounded-full px-1.5 text-[11px] leading-4 sm:inline',
                                active === item.target
                                    ? 'bg-primary-foreground/20'
                                    : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
                            ]"
                            aria-hidden="true"
                            >Hoje</span
                        >
                    </button>
                </li>
            </ul>
        </div>
    </nav>
</template>
