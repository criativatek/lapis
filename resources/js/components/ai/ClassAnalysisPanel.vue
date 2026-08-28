<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Lightbulb, RotateCcw, Sparkles, TrendingUp } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AiDisclosure from '@/components/ai/AiDisclosure.vue';
import { analyse } from '@/routes/results/statistics';

/**
 * «Analisar com IA» — a reading, in words, of the Estatística already on the
 * page.
 *
 * FOUR BLOCKS, NEVER A WALL OF TEXT AND NEVER JSON. Síntese, Padrões
 * observados, Pontos de atenção, Sugestões pedagógicas — the same four the
 * server parsed into typed lists (`ClassAnalysis`). The separation is
 * pedagogical: an observation about figures and a proposal for what to do
 * about them are different kinds of claim, and running them together is how
 * one quietly becomes the other.
 *
 * NOTHING HERE WRITES ANYTHING. There is no «aplicar», no «guardar», no
 * checkbox and no form — the only buttons are «analisar», «analisar de novo»
 * and «tentar novamente». That is not an omission to be filled in later: the
 * application is the source of truth for every grade, average, weight and
 * classification on this page, and an AI reading has no route back into any
 * of them.
 *
 * IT NEVER RUNS ON ITS OWN. The analysis costs money and is only ever
 * produced by an explicit click — opening Estatística calls no engine.
 */

type Analysis = {
    period_id: number | null;
    period_label: string | null;
    summary: string;
    patterns: string[];
    cautions: string[];
    suggestions: string[];
};

const props = withDefaults(
    defineProps<{
        ai: { available: boolean; reason: string | null };
        classUlid: string;
        /** The period the page is showing, so the reading is of what is on screen. */
        periodUlid?: string | null;
        /** «Dados até», carried through so a filtered page is analysed as filtered. */
        cutoff?: string | null;
        analysis?: Analysis | null;
        error?: { message: string } | null;
    }>(),
    { periodUlid: null, cutoff: null, analysis: null, error: null },
);

const analysing = ref(false);
const analysis = ref<Analysis | null>(props.analysis);
const error = ref<{ message: string } | null>(props.error);

watch(() => props.analysis, (value) => {
    analysis.value = value ?? null;
});
watch(() => props.error, (value) => {
    error.value = value ?? null;
});

/**
 * Three outcomes from the gateway's seven slugs, matching
 * `ClassStatisticsAnalysisController::unavailableMessage()` exactly. See the
 * Help panel's own note for why a teacher is not told which setting is blank.
 */
const unavailableMessage = computed(() => {
    if (props.ai.reason === 'plan') {
        return 'A análise pedagógica com IA não está incluída no plano desta organização.';
    }

    if (props.ai.reason === 'off') {
        return 'A análise pedagógica com IA não está ativada nesta instalação.';
    }

    return 'A análise pedagógica com IA não está configurada nesta instalação.';
});

const hasAnalysis = computed(() => analysis.value !== null);

function run(): void {
    if (!props.ai.available || analysing.value) {
        return;
    }

    router.post(
        analyse({ class: props.classUlid, ...(props.periodUlid ? { period: props.periodUlid } : {}) }).url,
        // «Dados até» only — the statistics themselves are rebuilt on the
        // server from the same read model the page used. Sending figures back
        // for the server to forward would let a tampered client choose what
        // the analysis describes.
        props.cutoff ? { ate: props.cutoff } : {},
        {
            preserveScroll: true,
            onStart: () => {
                analysing.value = true;
                analysis.value = null;
                error.value = null;
            },
            onFinish: () => {
                analysing.value = false;
            },
        },
    );
}
</script>

<template>
    <section aria-labelledby="analise-ia" class="rounded-lg border border-border p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="analise-ia" class="flex items-center gap-2 text-sm font-semibold">
                    <Sparkles aria-hidden="true" class="size-4" />
                    Analisar com IA
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Uma leitura em palavras dos números já calculados nesta página. A IA interpreta — não calcula, não
                    altera resultados e não regista nada.
                </p>
            </div>

            <button
                v-if="ai.available"
                type="button"
                :disabled="analysing"
                class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md border border-border px-3 text-sm font-medium hover:bg-muted/40 disabled:opacity-50"
                @click="run"
            >
                <Sparkles aria-hidden="true" class="size-3.5" />
                {{ analysing ? 'A analisar…' : hasAnalysis ? 'Analisar de novo' : 'Analisar com IA' }}
            </button>
        </div>

        <p
            v-if="!ai.available"
            class="mt-3 rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground"
        >
            {{ unavailableMessage }}
        </p>

        <div aria-live="polite" :aria-busy="analysing">
            <!-- Skeleton rather than a spinner: the four blocks that are
                 coming are visible as four blocks while they load, so the
                 page does not jump when they arrive. -->
            <div v-if="analysing" class="mt-4 space-y-3">
                <div class="h-3 w-2/3 animate-pulse rounded bg-muted"></div>
                <div class="h-3 w-full animate-pulse rounded bg-muted"></div>
                <div class="h-3 w-5/6 animate-pulse rounded bg-muted"></div>
                <p class="pt-1 text-xs text-muted-foreground">A interpretar os resultados desta turma…</p>
            </div>

            <div
                v-else-if="error"
                class="mt-4 rounded-lg bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-400"
            >
                <p>{{ error.message }}</p>
                <button
                    type="button"
                    class="mt-2 inline-flex items-center gap-1.5 font-medium underline underline-offset-2"
                    @click="run"
                >
                    <RotateCcw aria-hidden="true" class="size-3" />
                    Tentar novamente
                </button>
            </div>

            <div v-else-if="analysis" class="mt-4 space-y-4 border-t border-border/60 pt-4">
                <p v-if="analysis.period_label" class="text-xs text-muted-foreground">
                    Leitura de {{ analysis.period_label }}
                </p>

                <div>
                    <h3 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Síntese</h3>
                    <p class="mt-1 text-sm">{{ analysis.summary }}</p>
                </div>

                <div v-if="analysis.patterns.length > 0">
                    <h3 class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        <TrendingUp aria-hidden="true" class="size-3.5" />
                        Padrões observados
                    </h3>
                    <ul class="mt-1 space-y-1">
                        <li v-for="(item, index) in analysis.patterns" :key="index" class="text-sm">— {{ item }}</li>
                    </ul>
                </div>

                <div v-if="analysis.cautions.length > 0">
                    <h3 class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        <AlertTriangle aria-hidden="true" class="size-3.5" />
                        Pontos de atenção
                    </h3>
                    <ul class="mt-1 space-y-1">
                        <li v-for="(item, index) in analysis.cautions" :key="index" class="text-sm">— {{ item }}</li>
                    </ul>
                </div>

                <div v-if="analysis.suggestions.length > 0">
                    <h3 class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        <Lightbulb aria-hidden="true" class="size-3.5" />
                        Sugestões pedagógicas
                    </h3>
                    <ul class="mt-1 space-y-1">
                        <li v-for="(item, index) in analysis.suggestions" :key="index" class="text-sm">— {{ item }}</li>
                    </ul>
                </div>

                <AiDisclosure pseudonymised />
            </div>
        </div>
    </section>
</template>
