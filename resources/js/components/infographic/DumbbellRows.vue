<script setup lang="ts">
import { formatPoints, pct, prefersReducedMotion } from '@/lib/chartTheme';

/**
 * Two moments per row, as a dumbbell.
 *
 * A slopegraph earns a tall box when it has many crossing series to show; with
 * four or five it spends most of that height on air. A dumbbell says the same
 * thing in one line each: where it started, where it ended, and the distance
 * between — which is the whole question.
 *
 * BOTH ENDS ON THE SAME 0–100 TRACK, so the length of a connector IS the size
 * of its change and two rows can be compared against each other. Nothing is
 * stretched to look more dramatic, and a row with either end missing draws no
 * connector at all rather than one reaching to zero.
 */

export type Dumbbell = {
    id: number;
    label: string;
    /** 0–100. Null at either end means there is nothing to join. */
    from: number | null;
    to: number | null;
    colour: string;
};

const props = withDefaults(
    defineProps<{ rows: Dumbbell[]; fromLabel: string; toLabel: string; selectedId?: number | null }>(),
    { selectedId: null },
);

const emit = defineEmits<{ (event: 'select', id: number): void }>();

function isLit(id: number): boolean {
    return props.selectedId === null || props.selectedId === id;
}

function change(row: Dumbbell): number | null {
    return row.from === null || row.to === null ? null : row.to - row.from;
}

function left(row: Dumbbell): number {
    return Math.min(row.from ?? 0, row.to ?? 0);
}

function width(row: Dumbbell): number {
    return Math.abs((row.to ?? 0) - (row.from ?? 0));
}
</script>

<template>
    <div>
        <div class="mb-2 flex items-baseline justify-between text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            <span>{{ fromLabel }}</span>
            <span>{{ toLabel }}</span>
        </div>

        <ul class="space-y-1">
            <li v-for="row in rows" :key="row.id">
                <button
                    type="button"
                    class="grid w-full grid-cols-[minmax(5.5rem,auto)_1fr_auto] items-center gap-3 rounded-lg px-2 py-1.5 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="[
                        isLit(row.id) ? 'opacity-100' : 'opacity-35',
                        selectedId === row.id ? 'bg-muted/50' : 'hover:bg-muted/25',
                    ]"
                    :aria-pressed="selectedId === row.id"
                    @click="emit('select', row.id)"
                >
                    <span class="flex min-w-0 items-center gap-1.5">
                        <span class="size-2 shrink-0 rounded-full" :style="{ backgroundColor: row.colour }"></span>
                        <span class="truncate text-[11px] font-medium">{{ row.label }}</span>
                    </span>

                    <!-- The track is the scale; the bar between the two dots is
                         the change. -->
                    <span class="relative block h-5 w-full">
                        <span class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-border"></span>

                        <template v-if="row.from !== null && row.to !== null">
                            <span
                                class="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-full transition-all ease-out"
                                :class="prefersReducedMotion() ? 'duration-0' : 'duration-700'"
                                :style="{ left: `${left(row)}%`, width: `${width(row)}%`, backgroundColor: row.colour, opacity: 0.35 }"
                            ></span>
                            <span
                                class="absolute top-1/2 size-2 -translate-x-1/2 -translate-y-1/2 rounded-full border border-background"
                                :style="{ left: `${row.from}%`, backgroundColor: row.colour, opacity: 0.55 }"
                            ></span>
                            <span
                                class="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-background shadow-sm"
                                :style="{ left: `${row.to}%`, backgroundColor: row.colour }"
                            ></span>
                        </template>
                    </span>

                    <span class="flex shrink-0 items-baseline gap-2 tabular-nums">
                        <span class="text-[11px] text-muted-foreground">{{ pct(row.from === null ? null : String(row.from)) }}</span>
                        <span aria-hidden="true" class="text-muted-foreground/40">→</span>
                        <span class="text-xs font-semibold">{{ pct(row.to === null ? null : String(row.to)) }}</span>
                        <span
                            v-if="change(row) !== null"
                            class="w-16 text-right text-[11px] font-medium"
                            :class="change(row)! > 0 ? 'text-emerald-700 dark:text-emerald-400'
                                : change(row)! < 0 ? 'text-rose-700 dark:text-rose-400' : 'text-muted-foreground'"
                        >{{ formatPoints(change(row)) }}</span>
                        <span v-else class="w-16 text-right text-[10px] text-muted-foreground">sem comparação</span>
                    </span>
                </button>
            </li>
        </ul>
    </div>
</template>
