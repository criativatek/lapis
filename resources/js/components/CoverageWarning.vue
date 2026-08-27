<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { resultStateLabel } from '@/lib/coverage';
import type { Coverage } from '@/types';

/**
 * The ⚠ on the Resultados page, and the reason behind it.
 *
 * Two different things wear this icon, and the heading keeps them apart:
 *
 *  - "Cobertura parcial" — there IS a result, built on part of the applicable
 *    elements. Calling that "insuficiente" would be a contradiction: if the
 *    coverage were genuinely insufficient, Lapispro would not produce a value.
 *  - "Sem elementos avaliados" — no result, because nothing here can produce
 *    one yet. That is not partial coverage, it is the absence of coverage.
 *
 * The wording of each occurrence comes from the state actually recorded. This
 * component never infers WHY an element was left out — a late enrolment and an
 * absence are excluded by the same engine and must not be described alike.
 */
const props = withDefaults(
    defineProps<{
        coverage: Coverage;
        /** Whether the value beside the icon exists. This alone decides partial vs none. */
        hasValue: boolean;
        /** The page's domain columns, to name the ones left out of the calculation. */
        domains: { id: number; name: string }[];
        /** Which value is being explained — only changes how the empty case is worded. */
        scope?: 'domain' | 'overall';
    }>(),
    { scope: 'domain' },
);

/**
 * How each recorded state is described. Two families, because they are not the
 * same event: an absence leaves a question WITHOUT a classification that was
 * expected, while a dispensation or a non-applicable question was never going
 * to be classified at all.
 *
 * A state missing from this map gets neutral wording rather than an invented
 * reason — describing an occurrence the data does not support would be worse
 * than describing none.
 */
const OCCURRENCES: Record<string, { effect: string }> = {
    absent: { effect: 'sem classificação' },
    absent_justified: { effect: 'sem classificação' },
    exempt: { effect: 'não consideradas' },
    not_applicable: { effect: 'não consideradas' },
    annulled: { effect: 'não consideradas' },
};

// Partial coverage is a statement about a value that exists. Without a value
// there is nothing for the coverage to be partial OF.
const heading = computed<string>(() =>
    props.hasValue ? 'Cobertura parcial' : 'Sem elementos avaliados',
);

type Entry = { heading: string | null; detail: string };

const entries = computed<Entry[]>(() => {
    const lines: Entry[] = [];

    for (const exclusion of props.coverage.absences) {
        const questions =
            exclusion.item_count === 1
                ? '1 questão'
                : `${exclusion.item_count} questões`;
        const occurrence = OCCURRENCES[exclusion.reason];

        lines.push({
            heading: `${exclusion.instrument} · ${exclusion.applied_on}`,
            // The state's own name comes from the one map both screens read.
            detail: occurrence
                ? `${resultStateLabel(exclusion.reason)} — ${questions} ${occurrence.effect}.`
                : `${questions} fora do cálculo.`,
        });
    }

    // Only when nothing above has already accounted for the emptiness: stating
    // the occurrence and then "não existem elementos" is one fact told twice.
    if (lines.length === 0 && props.coverage.no_elements) {
        lines.push({
            heading: null,
            detail:
                props.scope === 'overall'
                    ? 'Ainda não existem elementos avaliados neste período.'
                    : 'Ainda não existem elementos avaliados neste domínio.',
        });
    }

    // Naming the dropped domains is informative beside a value — it says what
    // that value does NOT include. With no value at all it degenerates into
    // listing every domain, which explains nothing.
    if (props.hasValue) {
        const excluded = props.coverage.excluded_domain_ids
            .map((id) => props.domains.find((domain) => domain.id === id)?.name)
            .filter((name): name is string => name !== undefined);

        if (excluded.length > 0) {
            lines.push({
                heading: null,
                detail: `Sem elementos, fora do cálculo: ${excluded.join(', ')}.`,
            });
        }
    }

    // The flag is always explained — a general sentence beats an empty tooltip.
    if (lines.length === 0) {
        lines.push({
            heading: null,
            detail: 'Faltam elementos a este cálculo.',
        });
    }

    return lines;
});

// Said only where there is in fact a result: the point is that it is
// trustworthy, just built on less evidence than the rest of the class's.
const footer = computed<string | null>(() =>
    props.hasValue
        ? 'O resultado foi calculado com os restantes elementos.'
        : null,
);
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger
                class="cursor-help rounded-sm align-middle outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1"
                :aria-label="`${heading} — ver detalhe`"
            >
                <CircleAlert class="ml-0.5 inline size-3 text-amber-500" />
            </TooltipTrigger>
            <TooltipContent class="max-w-xs space-y-1.5 text-left">
                <p class="font-semibold">{{ heading }}</p>
                <div
                    v-for="(entry, index) in entries"
                    :key="`${entry.heading ?? ''}-${index}`"
                >
                    <p v-if="entry.heading" class="font-medium">
                        {{ entry.heading }}
                    </p>
                    <p>{{ entry.detail }}</p>
                </div>
                <p v-if="footer" class="opacity-80">{{ footer }}</p>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
