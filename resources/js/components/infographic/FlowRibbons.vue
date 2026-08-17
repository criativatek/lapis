<script setup lang="ts">
import { prefersReducedMotion, shade } from '@/lib/chartTheme';

/**
 * Movement across the class, as proportional arrows.
 *
 * A doughnut of four slices spends a large card saying «most of them went up»
 * and then makes the reader hover to find out by how many. Four arrows say the
 * same thing in a quarter of the height, with the count and the share written
 * beside each one — and an arrow POINTS, which is the whole subject here.
 *
 * The arrows point the way the movement went: forward for progress, backward
 * for regression, and blunt for the two that did not move. Direction is
 * reinforced by the word, the count and the arrowhead, never by colour alone.
 */

export type Flow = {
    key: string;
    label: string;
    count: number;
    /** 0–100 of the class, for the arrow's length. Null when there is nobody. */
    percent: number | null;
    /** Already formatted. */
    share: string;
    colour: string;
    direction: 'forward' | 'backward' | 'none';
    note?: string;
};

defineProps<{ flows: Flow[]; total: number }>();
</script>

<template>
    <ul class="space-y-2.5">
        <li v-for="flow in flows" :key="flow.key">
            <div class="mb-1 flex items-baseline justify-between gap-3">
                <span class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {{ flow.label }}
                </span>
                <span class="text-xs tabular-nums">
                    <span class="font-semibold">{{ flow.count }}</span>
                    <span class="text-muted-foreground"> · {{ flow.share }}</span>
                </span>
            </div>

            <div class="relative h-6 w-full rounded-md bg-muted/35">
                <div
                    v-if="flow.percent !== null && flow.count > 0"
                    class="absolute inset-y-0 transition-all ease-out"
                    :class="[
                        prefersReducedMotion() ? 'duration-0' : 'duration-500',
                        flow.direction === 'backward' ? 'right-0' : 'left-0',
                    ]"
                    :style="{
                        width: `${Math.max(flow.percent, 3)}%`,
                        background: `linear-gradient(180deg, ${shade(flow.colour, 0.18)}, ${flow.colour})`,
                        // The head is carved OUT of the length, never added to
                        // it, so the tip lands exactly where a plain bar ends.
                        clipPath: flow.direction === 'backward'
                            ? 'polygon(100% 0, 100% 100%, 12px 100%, 0 50%, 12px 0)'
                            : flow.direction === 'forward'
                                ? 'polygon(0 0, calc(100% - 12px) 0, 100% 50%, calc(100% - 12px) 100%, 0 100%)'
                                : 'none',
                    }"
                ></div>

                <span
                    v-else
                    class="absolute inset-y-0 left-2.5 flex items-center text-[11px] text-muted-foreground"
                >
                    Nenhum aluno
                </span>
            </div>

            <p v-if="flow.note" class="mt-1 text-[11px] leading-relaxed text-muted-foreground">{{ flow.note }}</p>
        </li>
    </ul>

    <p class="mt-3 text-[11px] text-muted-foreground">
        Percentagens sobre os {{ total }} alunos da turma.
    </p>
</template>
