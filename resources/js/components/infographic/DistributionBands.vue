<script setup lang="ts">
import { prefersReducedMotion } from '@/lib/chartTheme';
import type { QualitativeTone } from '@/lib/qualitativeTone';

/**
 * The distribution across the scale, as one small card per band.
 *
 * Rows of thin bars said the same thing as the plates before them and looked
 * like every other progress list on the page. A band is an OBJECT — it has a
 * name, a count and a share — so it gets an object: a tile with the count set
 * large enough to be the first thing read, its own tinted ground in the band's
 * colour, and a short fill along the bottom for the share.
 *
 * THE ONLY LENGTH IS THE SHARE. The tile's size is fixed; nothing about its
 * area encodes a quantity, so five bands look like five bands whatever the
 * class did. A band with nobody in it keeps its tile, its zero and its words —
 * a level nobody reached is a fact about the class, and dropping it would
 * redraw the row per class.
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
        <ul class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-5">
            <li v-for="band in bands" :key="band.scale_level_id">
                <button
                    type="button"
                    class="relative block h-full w-full overflow-hidden rounded-xl border p-3 text-left transition-all focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="[
                        band.count === 0
                            ? 'border-dashed border-border/60 bg-muted/20'
                            : 'border-transparent',
                        isLit(band.scale_level_id) ? 'opacity-100' : 'opacity-40',
                        selectedId === band.scale_level_id ? 'ring-2 ring-primary/50' : '',
                        prefersReducedMotion() || band.count === 0 ? '' : 'hover:-translate-y-0.5',
                    ]"
                    :style="band.count === 0 ? undefined : {
                        // The band's own colour, at paper strength.
                        backgroundColor: `color-mix(in srgb, ${band.colour} 10%, transparent)`,
                    }"
                    :aria-pressed="selectedId === band.scale_level_id"
                    @click="emit('select', band.scale_level_id)"
                >
                    <span class="flex items-center gap-1.5">
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :style="{ backgroundColor: band.colour, opacity: band.count === 0 ? 0.35 : 1 }"
                        ></span>
                        <span
                            class="truncate text-[10px] font-semibold uppercase tracking-wider"
                            :class="band.count === 0 ? 'text-muted-foreground' : 'text-foreground/80'"
                        >{{ band.label }}</span>
                    </span>

                    <p
                        class="mt-2 text-[2rem] font-semibold leading-none tabular-nums tracking-tight"
                        :class="band.count === 0 ? 'text-muted-foreground/50' : ''"
                    >
                        {{ band.count }}
                    </p>
                    <p class="mt-0.5 text-[11px] tabular-nums text-muted-foreground">
                        {{ band.share }}<span class="ml-1">{{ band.count === 1 ? 'aluno' : 'alunos' }}</span>
                    </p>

                    <!-- The share, as a rule along the foot of the tile. -->
                    <span class="absolute inset-x-0 bottom-0 h-1 bg-black/5 dark:bg-white/5">
                        <span
                            class="block h-full transition-all ease-out"
                            :class="prefersReducedMotion() ? 'duration-0' : 'duration-700'"
                            :style="{
                                width: band.percent === null ? '0%' : `${band.percent}%`,
                                backgroundColor: band.colour,
                            }"
                        ></span>
                    </span>
                </button>
            </li>
        </ul>

        <p class="mt-3 text-[11px] text-muted-foreground">
            {{ placed === 1 ? '1 aluno colocado na escala' : `${placed} alunos colocados na escala` }}.
            Um nível vazio continua visível — é um facto sobre a turma.
        </p>
    </div>
</template>
