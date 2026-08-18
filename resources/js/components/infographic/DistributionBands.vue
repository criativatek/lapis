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
    /** The selection key: a level id, or the value on a numeric scale. */
    key: string;
    scale_level_id: number | null;
    /** The classification itself — «2», «14», «Atingiu». */
    code: string;
    /** The mention beside it, when the scale has one. */
    label: string | null;
    count: number;
    /** 0–100 of the students placed on the scale. Null when nobody is. */
    percent: number | null;
    /** Already formatted. */
    share: string;
    tone: QualitativeTone;
    colour: string;
};

const props = withDefaults(
    defineProps<{
        bands: DistributionBand[];
        placed: number;
        selectedKey?: string | null;
        /**
         * Which of the two lines leads.
         *
         * «value» for the grades the teacher assigned — the classification IS
         * «2», and «Insuficiente» is the mention beside it. «mention» for the
         * calculated reading, where there is no assigned value and the band's
         * words genuinely are the answer (§2.3, §2.10).
         */
        leadWith?: 'value' | 'mention';
    }>(),
    { selectedKey: null, leadWith: 'mention' },
);

const emit = defineEmits<{ (event: 'select', key: string): void }>();

function isLit(key: string): boolean {
    return props.selectedKey === null || props.selectedKey === key;
}
</script>

<template>
    <div>
        <ul class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-5">
            <li v-for="band in bands" :key="band.key">
                <button
                    type="button"
                    class="relative block h-full w-full overflow-hidden rounded-xl border p-3 text-left transition-all focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="[
                        band.count === 0
                            ? 'border-dashed border-border/60 bg-muted/20'
                            : 'border-transparent',
                        isLit(band.key) ? 'opacity-100' : 'opacity-40',
                        selectedKey === band.key ? 'ring-2 ring-primary/50' : '',
                        prefersReducedMotion() || band.count === 0 ? '' : 'hover:-translate-y-0.5',
                    ]"
                    :style="band.count === 0 ? undefined : {
                        // The band's own colour, at paper strength.
                        backgroundColor: `color-mix(in srgb, ${band.colour} 10%, transparent)`,
                    }"
                    :aria-pressed="selectedKey === band.key"
                    :aria-label="leadWith === 'value'
                        ? `Classificação ${band.code}${band.label ? `, ${band.label}` : ''}, ${band.count === 1 ? '1 aluno' : `${band.count} alunos`}, ${band.share}`
                        : `${band.label}, ${band.count === 1 ? '1 aluno' : `${band.count} alunos`}, ${band.share}`"
                    @click="emit('select', band.key)"
                >
                    <!-- THE CLASSIFICATION LEADS, the mention follows. A «2» on
                         a pauta is the grade; «Insuficiente» is what the scale
                         calls it, and putting the words first would answer a
                         question about grades with a description (§2.3, §2.8). -->
                    <template v-if="leadWith === 'value'">
                        <span class="flex items-baseline gap-1.5">
                            <span
                                class="size-2 shrink-0 self-center rounded-full"
                                :style="{ backgroundColor: band.colour, opacity: band.count === 0 ? 0.35 : 1 }"
                            ></span>
                            <span
                                class="truncate text-[1.75rem] font-semibold leading-none tabular-nums tracking-tight"
                                :class="band.count === 0 ? 'text-muted-foreground/60' : ''"
                            >{{ band.code }}</span>
                        </span>

                        <p
                            v-if="band.label"
                            class="mt-1 truncate text-[10px] font-medium uppercase tracking-wider"
                            :class="band.count === 0 ? 'text-muted-foreground/70' : 'text-foreground/70'"
                        >{{ band.label }}</p>

                        <p class="mt-2 text-sm tabular-nums" :class="band.count === 0 ? 'text-muted-foreground/60' : ''">
                            <span class="font-semibold">{{ band.count }}</span>
                            <span class="ml-1 text-muted-foreground">{{ band.count === 1 ? 'aluno' : 'alunos' }}</span>
                        </p>
                        <p class="text-[11px] tabular-nums text-muted-foreground">{{ band.share }}</p>
                    </template>

                    <!-- The calculated reading has no assigned value to lead
                         with: the band's words genuinely are the answer. -->
                    <template v-else>
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
                    </template>

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
