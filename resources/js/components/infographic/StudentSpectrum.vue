<script setup lang="ts">
import { computed, ref } from 'vue';
import { pct, prefersReducedMotion } from '@/lib/chartTheme';

/**
 * Where the class sits on the scale, student by student.
 *
 * ONE AXIS, 0 TO 100, AND ONE DOT PER STUDENT at their own Média Ponderada —
 * the canonical figure, placed and not recomputed. What it answers is the only
 * question none of the other views does: are they bunched or spread, and does
 * the average describe anybody or nobody.
 *
 * THIS IS NOT A RANKING. Nothing is ordered by result, no position is
 * numbered, and nobody is labelled strong or weak: a dot is at its value
 * because that is its value. The ends are «0%» and «100%», which are
 * properties of the scale rather than of the people on it (§16, §17).
 *
 * Students with no result have no place on an axis of results and are counted
 * beside it instead — an absence is not a zero.
 */

export type SpectrumPoint = {
    enrollment_id: number;
    name: string;
    /** 0–100. Only students who have one are passed in. */
    percent: number;
    display: string;
    mention: string | null;
    mentionClass: string;
    classNumber: number | null;
};

const props = withDefaults(
    defineProps<{
        points: SpectrumPoint[];
        /** The class average, drawn as a reference line. Null hides it. */
        averagePercent: number | null;
        averageDisplay: string;
        withoutResult: number;
    }>(),
    {},
);

const emit = defineEmits<{ (event: 'select', enrollmentId: number): void }>();

const hovered = ref<number | null>(null);

/**
 * Dots at the same value would sit on top of each other, so they stack
 * upwards instead. Rounded to the nearest whole percent for the purpose of
 * deciding «same place» — which is what the eye does anyway.
 */
const laid = computed(() => {
    const lanes = new Map<number, number>();

    return [...props.points]
        .sort((a, b) => a.percent - b.percent)
        .map((point) => {
            const slot = Math.round(point.percent);
            const lane = lanes.get(slot) ?? 0;

            lanes.set(slot, lane + 1);

            return { ...point, lane };
        });
});

const tallest = computed(() => Math.max(1, ...laid.value.map((point) => point.lane + 1)));
</script>

<template>
    <div>
        <div
            class="relative w-full"
            :style="{ height: `${Math.max(96, tallest * 26 + 44)}px` }"
        >
            <!-- The axis. Structure, not data. -->
            <div class="absolute inset-x-0 bottom-8 h-px bg-border"></div>

            <!-- The class average, as a reference the dots can be read against. -->
            <template v-if="averagePercent !== null">
                <div
                    class="absolute bottom-8 top-0 w-px bg-primary/40"
                    :style="{ left: `${averagePercent}%` }"
                ></div>
                <span
                    class="absolute -translate-x-1/2 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary"
                    :style="{ left: `${averagePercent}%`, bottom: '0.25rem' }"
                >
                    Média {{ averageDisplay }}
                </span>
            </template>

            <button
                v-for="point in laid"
                :key="point.enrollment_id"
                type="button"
                class="absolute -translate-x-1/2 rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="prefersReducedMotion() ? '' : 'transition-transform duration-150 hover:scale-125'"
                :style="{ left: `${point.percent}%`, bottom: `${2.25 + point.lane * 1.5}rem` }"
                :aria-label="`${point.name}: ${point.display}${point.mention ? ` — ${point.mention}` : ''}`"
                @click="emit('select', point.enrollment_id)"
                @mouseenter="hovered = point.enrollment_id"
                @mouseleave="hovered = null"
                @focus="hovered = point.enrollment_id"
                @blur="hovered = null"
            >
                <span
                    class="flex size-6 items-center justify-center rounded-full text-[10px] font-semibold shadow-sm ring-1 ring-black/5 dark:ring-white/10"
                    :class="point.mentionClass"
                >
                    {{ point.classNumber ?? '·' }}
                </span>
            </button>

            <!-- The name, on demand. The dot carries the roll number, which is
                 short enough to sit inside it without becoming a label. -->
            <div
                v-if="hovered !== null"
                class="pointer-events-none absolute -top-1 z-20 -translate-x-1/2 rounded-lg border border-border/80 bg-popover/95 px-2.5 py-1.5 shadow-lg backdrop-blur-sm"
                :style="{ left: `${laid.find((point) => point.enrollment_id === hovered)?.percent ?? 50}%` }"
            >
                <p class="text-[11px] font-semibold">{{ laid.find((point) => point.enrollment_id === hovered)?.name }}</p>
                <p class="text-[11px] tabular-nums text-muted-foreground">
                    {{ laid.find((point) => point.enrollment_id === hovered)?.display }}
                    <template v-if="laid.find((point) => point.enrollment_id === hovered)?.mention">
                        · {{ laid.find((point) => point.enrollment_id === hovered)?.mention }}
                    </template>
                </p>
            </div>

            <span class="absolute bottom-1 left-0 text-[10px] text-muted-foreground">{{ pct('0') }}</span>
            <span class="absolute bottom-1 right-0 text-[10px] text-muted-foreground">{{ pct('100') }}</span>
        </div>

        <p v-if="withoutResult > 0" class="mt-1 text-[11px] text-muted-foreground">
            {{ withoutResult === 1 ? '1 aluno sem resultado' : `${withoutResult} alunos sem resultado` }}
            neste período — sem lugar num eixo de resultados, e nunca colocado no zero.
        </p>

        <!-- The same data in words: a dot is a position, and a position is not
             readable without sight. -->
        <table class="sr-only">
            <caption>Cada aluno da turma na sua Média Ponderada, de 0 a 100 por cento.</caption>
            <thead>
                <tr><th scope="col">Aluno</th><th scope="col">Média</th><th scope="col">Menção</th></tr>
            </thead>
            <tbody>
                <tr v-for="point in laid" :key="point.enrollment_id">
                    <th scope="row">{{ point.name }}</th>
                    <td>{{ point.display }}</td>
                    <td>{{ point.mention ?? '—' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
