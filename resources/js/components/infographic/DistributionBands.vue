<script setup lang="ts">
import { prefersReducedMotion } from '@/lib/chartTheme';
import type { QualitativeTone } from '@/lib/qualitativeTone';

/**
 * The distribution across the scale, as one filled row per band.
 *
 * The plates this replaces were honest but airy: five objects with four short
 * numbers each, spread across a card that then had to be tall enough for the
 * tallest of them. A row carries the same four facts — band, count, share, bar
 * — in a line, so five bands fill a card instead of floating in one.
 *
 * THE BAR IS THE SHARE AND NOTHING ELSE. Its width is the percentage of the
 * students placed on the scale; the count beside it is the count. A band with
 * nobody in it keeps its row, its zero and its words: a level nobody reached is
 * a fact about the class, and dropping it would redraw the axis per class.
 */

export type DistributionBand = {
    scale_level_id: number;
    code: string;
    label: string;
    count: number;
    /** 0–100 of the students placed on the scale. Null when nobody is. */
    percent: number | null;
    /** Already formatted. */
    share: string;
    tone: QualitativeTone;
    colour: string;
};

const props = withDefaults(
    defineProps<{ bands: DistributionBand[]; placed: number; selectedId?: number | null }>(),
    { selectedId: null },
);

const emit = defineEmits<{ (event: 'select', scaleLevelId: number): void }>();

function isLit(id: number): boolean {
    return props.selectedId === null || props.selectedId === id;
}
</script>

<template>
    <div>
        <ul class="space-y-1.5">
            <li v-for="band in bands" :key="band.scale_level_id">
                <button
                    type="button"
                    class="group grid w-full grid-cols-[minmax(6.5rem,auto)_1fr_auto] items-center gap-3 rounded-xl px-2.5 py-2 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="[
                        isLit(band.scale_level_id) ? 'opacity-100' : 'opacity-40',
                        selectedId === band.scale_level_id ? 'bg-muted/60' : 'hover:bg-muted/30',
                    ]"
                    :aria-pressed="selectedId === band.scale_level_id"
                    @click="emit('select', band.scale_level_id)"
                >
                    <span class="flex min-w-0 items-center gap-2">
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :style="{ backgroundColor: band.colour, opacity: band.count === 0 ? 0.3 : 1 }"
                        ></span>
                        <span
                            class="truncate text-[11px] font-semibold uppercase tracking-wider"
                            :class="band.count === 0 ? 'text-muted-foreground' : ''"
                        >{{ band.label }}</span>
                    </span>

                    <span class="h-2.5 w-full overflow-hidden rounded-full bg-muted/50">
                        <span
                            class="block h-full rounded-full transition-all ease-out"
                            :class="prefersReducedMotion() ? 'duration-0' : 'duration-700'"
                            :style="{
                                width: band.percent === null ? '0%' : `${band.percent}%`,
                                backgroundColor: band.colour,
                            }"
                        ></span>
                    </span>

                    <span class="flex items-baseline gap-2 tabular-nums">
                        <span
                            class="text-lg font-semibold leading-none"
                            :class="band.count === 0 ? 'text-muted-foreground/60' : ''"
                        >{{ band.count }}</span>
                        <span class="w-12 text-right text-[11px] text-muted-foreground">{{ band.share }}</span>
                    </span>
                </button>
            </li>
        </ul>

        <p class="mt-2.5 px-2.5 text-[11px] text-muted-foreground">
            {{ placed === 1 ? '1 aluno colocado na escala' : `${placed} alunos colocados na escala` }}.
            Um nível vazio continua visível — é um facto sobre a turma.
        </p>
    </div>
</template>
