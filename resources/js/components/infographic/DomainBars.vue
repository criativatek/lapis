<script setup lang="ts">
import { prefersReducedMotion, shade } from '@/lib/chartTheme';

/**
 * The domains, as small cards rather than as rows of progress.
 *
 * A bare bar on a white page is furniture; the eye slides off it. Each domain
 * now sits on its own tinted ground in its own colour, with the value set large
 * on the same line as the name — so a domain reads as a thing with a state
 * rather than as one more line in a list.
 *
 * THREE STATEMENTS, THREE LANGUAGES, AND NONE OF THEM BORROWS ANOTHER'S INK:
 *
 *  - the ground and the bar are the DOMAIN's structural colour, which says
 *    «this is Leitura» and stays that colour in the slopegraph and the map;
 *  - the badge is the MENTION, in the scale's own tone, which says how it is
 *    going;
 *  - the change is MOVEMENT, in the trend inks, which says which way it went.
 *
 * A domain drawn in red because it fell would read as a domain that is failing,
 * which is a different claim about a different thing.
 *
 * The bar's length is the percentage itself — never scaled to the largest
 * value, which would make a class of 40s look like a class of 90s.
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
    <ul class="grid gap-2.5 md:grid-cols-2">
        <li v-for="bar in bars" :key="bar.id">
            <button
                type="button"
                class="block h-full w-full rounded-xl border border-transparent p-3.5 text-left transition-all focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="[
                    isLit(bar.id) ? 'opacity-100' : 'opacity-40',
                    selectedId === bar.id ? 'ring-2 ring-primary/40' : '',
                    prefersReducedMotion() ? '' : 'hover:-translate-y-0.5',
                ]"
                :style="{ backgroundColor: `color-mix(in srgb, ${bar.colour} 8%, transparent)` }"
                :aria-pressed="selectedId === bar.id"
                @click="emit('select', bar.id)"
            >
                <div class="flex items-baseline gap-2">
                    <span class="size-2.5 shrink-0 translate-y-px rounded-full" :style="{ backgroundColor: bar.colour }"></span>
                    <span class="truncate text-[11px] font-semibold uppercase tracking-wider">{{ bar.label }}</span>
                    <span class="ml-auto text-2xl font-semibold leading-none tabular-nums tracking-tight">{{ bar.display }}</span>
                </div>

                <div class="mt-2.5 h-3.5 w-full overflow-hidden rounded-full bg-black/5 dark:bg-white/10">
                    <div
                        v-if="bar.percent !== null"
                        class="h-full rounded-full transition-all ease-out"
                        :class="prefersReducedMotion() ? 'duration-0' : 'duration-700'"
                        :style="{
                            width: `${Math.max(bar.percent, 1)}%`,
                            background: `linear-gradient(90deg, ${shade(bar.colour, 0.3)}, ${bar.colour})`,
                        }"
                    ></div>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <span
                        v-if="bar.mention"
                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                        :class="bar.mentionClass"
                    >{{ bar.mention }}</span>

                    <span
                        v-if="bar.change"
                        class="text-[11px] font-medium tabular-nums"
                        :class="bar.direction === 'up' ? 'text-emerald-700 dark:text-emerald-400'
                            : bar.direction === 'down' ? 'text-rose-700 dark:text-rose-400' : 'text-muted-foreground'"
                    >
                        {{ bar.direction === 'up' ? '↑' : bar.direction === 'down' ? '↓' : '→' }} {{ bar.change }} p.p.
                    </span>

                    <span v-if="bar.percent === null" class="text-[11px] text-muted-foreground">
                        Sem resultado neste período
                    </span>
                    <span v-else-if="bar.students" class="ml-auto text-[11px] text-muted-foreground">{{ bar.students }}</span>
                </div>
            </button>
        </li>
    </ul>
</template>
