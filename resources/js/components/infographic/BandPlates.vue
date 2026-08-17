<script setup lang="ts">
import { prefersReducedMotion, shade } from '@/lib/chartTheme';
import { qualitativeToneClasses } from '@/lib/qualitativeTone';
import type { QualitativeTone } from '@/lib/qualitativeTone';

/**
 * The distribution across the scale, as a flight of plates instead of bars.
 *
 * NO AXIS, AND NO CHART LIBRARY. A bar chart of five bands spends most of its
 * area on empty plot and asks the reader to travel from a column to a tick to a
 * label; the count is the only number there and it is never large. A row of
 * plates puts the band, its words, its count and its share in one object, and
 * lets the eye compare four short numbers instead of five heights.
 *
 * THE STEP IS THE SCALE'S OWN ORDER, NOT THE VALUE. Each plate sits a little
 * lower than the one after it because the bands are ranked — «Fraco» through
 * «Muito Bom» — and that offset is a constant, identical whether a band holds
 * nobody or the whole class. Nothing here encodes a quantity as a length, so
 * nothing here can distort one.
 */

export type BandPlate = {
    scale_level_id: number;
    code: string;
    label: string;
    count: number;
    /** Already formatted — this component decides no arithmetic. */
    share: string;
    tone: QualitativeTone;
    /** The ink of the tone, for the plate's own edges. */
    colour: string;
};

const props = withDefaults(
    defineProps<{
        plates: BandPlate[];
        selectedId?: number | null;
        /** Total placed on the scale, for the caption. */
        placed: number;
    }>(),
    { selectedId: null },
);

const emit = defineEmits<{ (event: 'select', scaleLevelId: number): void }>();

function isLit(id: number): boolean {
    return props.selectedId === null || props.selectedId === id;
}
</script>

<template>
    <div>
        <ol class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
            <li
                v-for="(plate, index) in plates"
                :key="plate.scale_level_id"
                class="lg:pb-0"
                :style="{
                    // The rank, as a step. A constant offset per position — it
                    // says «this band ranks higher», never «this band is larger».
                    marginBottom: `${index * 10}px`,
                }"
            >
                <button
                    type="button"
                    class="relative block w-full overflow-hidden rounded-xl border p-4 text-left transition-all duration-200 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="[
                        plate.count === 0
                            ? 'border-dashed border-border/70 bg-muted/20'
                            : 'border-border bg-card shadow-[0_1px_0_0_var(--border),0_10px_22px_-18px_rgba(0,0,0,0.5)] dark:shadow-[0_1px_0_0_rgba(255,255,255,0.06),0_12px_26px_-20px_rgba(0,0,0,0.9)]',
                        isLit(plate.scale_level_id) ? 'opacity-100' : 'opacity-40',
                        selectedId === plate.scale_level_id ? 'ring-2 ring-primary/50' : '',
                        prefersReducedMotion() || plate.count === 0 ? '' : 'hover:-translate-y-0.5',
                    ]"
                    :aria-pressed="selectedId === plate.scale_level_id"
                    @click="emit('select', plate.scale_level_id)"
                >
                    <!-- The band's own ink along the top edge — the plate's
                         identity, not a quantity. -->
                    <span
                        aria-hidden="true"
                        class="absolute inset-x-0 top-0 h-1"
                        :style="{
                            background: plate.count === 0
                                ? 'transparent'
                                : `linear-gradient(90deg, ${plate.colour}, ${shade(plate.colour, 0.35)})`,
                        }"
                    ></span>

                    <p class="text-[11px] font-semibold tabular-nums text-muted-foreground/70">
                        {{ String(plate.code) }}
                    </p>
                    <p
                        class="mt-0.5 truncate text-xs font-semibold uppercase tracking-wider"
                        :class="plate.count === 0 ? 'text-muted-foreground' : ''"
                    >
                        {{ plate.label }}
                    </p>

                    <p class="mt-2.5 flex items-baseline gap-1.5">
                        <span
                            class="text-2xl font-semibold leading-none tabular-nums tracking-tight"
                            :class="plate.count === 0 ? 'text-muted-foreground/60' : ''"
                        >{{ plate.count }}</span>
                        <span class="text-[11px] text-muted-foreground">{{ plate.count === 1 ? 'aluno' : 'alunos' }}</span>
                    </p>

                    <!-- The share as a short rule under the number: proportional,
                         and the only length in the whole composition. -->
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-muted/60">
                        <div
                            class="h-full rounded-full transition-all ease-out"
                            :class="prefersReducedMotion() ? 'duration-0' : 'duration-500'"
                            :style="{
                                width: plate.share === '—' ? '0%' : plate.share,
                                backgroundColor: plate.colour,
                            }"
                        ></div>
                    </div>
                    <p class="mt-1 text-[11px] tabular-nums text-muted-foreground">{{ plate.share }}</p>

                    <span class="sr-only" :class="qualitativeToneClasses[plate.tone]"></span>
                </button>
            </li>
        </ol>

        <p class="mt-3 text-[11px] text-muted-foreground">
            {{ placed === 1 ? '1 aluno colocado na escala' : `${placed} alunos colocados na escala` }}.
            As bandas sem alunos continuam visíveis — um nível vazio é um facto sobre a turma.
        </p>
    </div>
</template>
