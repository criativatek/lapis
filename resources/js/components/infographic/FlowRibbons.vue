<script setup lang="ts">
import { computed } from 'vue';
import { prefersReducedMotion } from '@/lib/chartTheme';

/**
 * Movement across the class, as ONE segmented bar and a short legend.
 *
 * Four separate arrows made the reader measure four lengths against four
 * different starting points to answer a question about proportions. A single
 * bar answers it in one look — the segments are shares of the same whole, so
 * «most went up» is the shape of the bar rather than something to work out.
 *
 * The legend underneath carries the counts and the percentages, because a
 * proportion is not a number and a teacher needs both. Direction survives in
 * the arrow beside each label, so nothing here depends on colour alone.
 */

export type Flow = {
    key: string;
    label: string;
    count: number;
    /** 0–100 of the class. Null when there is nobody at all. */
    percent: number | null;
    /** Already formatted. */
    share: string;
    colour: string;
    direction: 'forward' | 'backward' | 'none';
    note?: string;
};

const props = defineProps<{ flows: Flow[]; total: number }>();

/** Only the segments that have somebody in them get width in the bar. */
const segments = computed(() => props.flows.filter((flow) => flow.count > 0));

const arrow: Record<Flow['direction'], string> = {
    forward: '↑',
    backward: '↓',
    none: '→',
};
</script>

<template>
    <div>
        <!-- One bar, one whole. -->
        <div class="flex h-3.5 w-full overflow-hidden rounded-full bg-muted/50">
            <span
                v-for="segment in segments"
                :key="segment.key"
                class="h-full first:rounded-l-full last:rounded-r-full transition-all ease-out"
                :class="prefersReducedMotion() ? 'duration-0' : 'duration-700'"
                :style="{ width: `${segment.percent}%`, backgroundColor: segment.colour }"
                :title="`${segment.label}: ${segment.count} · ${segment.share}`"
            ></span>
        </div>

        <ul class="mt-3.5 space-y-1.5">
            <li v-for="flow in flows" :key="flow.key" class="flex items-baseline gap-2 text-sm">
                <span
                    aria-hidden="true"
                    class="w-3 shrink-0 text-center text-xs"
                    :style="{ color: flow.count > 0 ? flow.colour : undefined }"
                    :class="flow.count === 0 ? 'text-muted-foreground/50' : ''"
                >{{ arrow[flow.direction] }}</span>

                <span class="text-muted-foreground" :class="flow.count === 0 ? 'opacity-60' : ''">{{ flow.label }}</span>

                <span class="ml-auto tabular-nums font-semibold" :class="flow.count === 0 ? 'text-muted-foreground/60' : ''">
                    {{ flow.count }}
                </span>
                <span class="w-14 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{{ flow.share }}</span>
            </li>
        </ul>

        <p
            v-for="flow in flows.filter((row) => row.note && row.count > 0)"
            :key="`${flow.key}-note`"
            class="mt-2 text-[11px] leading-relaxed text-muted-foreground"
        >
            {{ flow.note }}
        </p>

        <p class="mt-2 text-[11px] text-muted-foreground">
            Percentagens sobre os {{ total }} alunos da turma.
        </p>
    </div>
</template>
