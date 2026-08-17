<script setup lang="ts">
import { prefersReducedMotion, shade } from '@/lib/chartTheme';

/**
 * The domains, as wide progress bars.
 *
 * THREE STATEMENTS, THREE LANGUAGES, ON ONE LINE (§15 of the earlier decision,
 * §14–§15 here):
 *
 *  - the bar is the DOMAIN's own structural ink, so «this is Leitura» is
 *    readable at a glance and stays the same ink in the slopegraph and the map;
 *  - the badge is the MENTION, in the scale's own tone, which says how it is
 *    going;
 *  - the change is MOVEMENT, in the trend inks, which says which way it went.
 *
 * Merging any two of them would be claiming something none of them says. A
 * domain drawn in red because it fell would read as a domain that is failing.
 *
 * The bar's length is the percentage itself — no scaling to the largest value,
 * which would make a class of 40s look like a class of 90s.
 */

export type DomainBar = {
    id: number;
    label: string;
    /** 0–100, or null when there is no value. Never a zero standing in. */
    percent: number | null;
    /** Already formatted. */
    display: string;
    colour: string;
    mention?: string | null;
    mentionClass?: string;
    /** Signed, already formatted. */
    change?: string | null;
    direction?: 'up' | 'down' | 'flat' | null;
    students?: string | null;
};

const props = withDefaults(
    defineProps<{ bars: DomainBar[]; selectedId?: number | null }>(),
    { selectedId: null },
);

const emit = defineEmits<{ (event: 'select', id: number): void }>();

function isLit(id: number): boolean {
    return props.selectedId === null || props.selectedId === id;
}
</script>

<template>
    <ul class="space-y-3.5">
        <li v-for="bar in bars" :key="bar.id">
            <button
                type="button"
                class="block w-full rounded-xl px-2.5 py-2 text-left transition-all focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="[
                    isLit(bar.id) ? 'opacity-100' : 'opacity-40',
                    selectedId === bar.id ? 'bg-muted/50' : 'hover:bg-muted/25',
                ]"
                :aria-pressed="selectedId === bar.id"
                @click="emit('select', bar.id)"
            >
                <div class="mb-1.5 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="size-2 shrink-0 translate-y-px rounded-full" :style="{ backgroundColor: bar.colour }"></span>
                    <span class="text-[11px] font-semibold uppercase tracking-wider">{{ bar.label }}</span>

                    <span
                        v-if="bar.change"
                        class="text-[11px] font-medium tabular-nums"
                        :class="bar.direction === 'up' ? 'text-emerald-600 dark:text-emerald-400'
                            : bar.direction === 'down' ? 'text-rose-600 dark:text-rose-400' : 'text-muted-foreground'"
                    >
                        {{ bar.direction === 'up' ? '↑' : bar.direction === 'down' ? '↓' : '→' }} {{ bar.change }} p.p.
                    </span>

                    <span class="ml-auto flex items-baseline gap-2.5">
                        <span
                            v-if="bar.mention"
                            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="bar.mentionClass"
                        >{{ bar.mention }}</span>
                        <span class="text-xl font-semibold leading-none tabular-nums tracking-tight">{{ bar.display }}</span>
                    </span>
                </div>

                <div class="h-3 w-full overflow-hidden rounded-full bg-muted/50">
                    <div
                        v-if="bar.percent !== null"
                        class="h-full rounded-full transition-all ease-out"
                        :class="prefersReducedMotion() ? 'duration-0' : 'duration-700'"
                        :style="{
                            width: `${Math.max(bar.percent, 1)}%`,
                            background: `linear-gradient(90deg, ${shade(bar.colour, 0.25)}, ${bar.colour})`,
                        }"
                    ></div>
                </div>

                <p v-if="bar.percent === null" class="mt-1 text-[11px] text-muted-foreground">
                    Sem resultado neste período
                </p>
                <p v-else-if="bar.students" class="mt-1 text-[11px] text-muted-foreground">{{ bar.students }}</p>
            </button>
        </li>
    </ul>
</template>
