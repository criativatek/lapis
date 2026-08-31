<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { RotateCcw, Sparkles } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AiDisclosure from '@/components/ai/AiDisclosure.vue';

/**
 * The shape every «pedir uma leitura à IA» panel in this application has.
 *
 * ONE COMPONENT SO THERE IS ONE BEHAVIOUR (§21). Before it there was one:
 * `ClassAnalysisPanel`, on Estatística. The AI-complete slice adds two more —
 * the reading of a period's Resultados and the synthesis of a student's
 * Evolução — and three hand-written copies of «disclosure, botão explícito,
 * loading, resultado, retry, quota, indisponível, erro» would have become three
 * slightly different ideas of what a quota message looks like within a release.
 *
 * WHAT IS SHARED AND WHAT IS NOT. Shared: the states and the transitions
 * between them, the skeleton, the retry affordance, the disclosure, the
 * accessibility wiring. Not shared: the sections themselves, which each feature
 * describes as data — `title`, `kind`, `items` — because what a reading is MADE
 * OF is a pedagogical decision and the panel has no business having an opinion
 * about it.
 *
 * IT NEVER RUNS ON ITS OWN. A reading costs money and is only ever produced by
 * an explicit click. Opening a page calls no engine, and there is no `onMounted`
 * anywhere in this file.
 *
 * NOTHING HERE WRITES ANYTHING. There is no «aplicar», no «guardar», no
 * checkbox and no form — the only buttons are «pedir», «pedir de novo» and
 * «tentar novamente». That is not an omission to be filled in later: the
 * application is the source of truth for every figure on the page behind this
 * panel, and an AI reading has no route back into any of them.
 *
 * TEXT IS RENDERED AS TEXT. Every string below reaches the DOM through Vue's
 * interpolation, never `v-html`, so a model that answered with markup produces
 * visible characters rather than an element — the server already strips
 * markdown, and this is the second half of the same guarantee.
 */

/** One block of a reading: a heading and its lines. */
export type AiReadingSection = {
    title: string;
    /** A paragraph, when the block is prose (the summary). Mutually exclusive with `items`. */
    text?: string | null;
    /** Bulleted lines, when the block is a list. */
    items?: string[];
};

const props = withDefaults(
    defineProps<{
        /** The panel's own heading — «Analisar a avaliação com IA». */
        title: string;
        /** One sentence under it saying what the reading is and is not. */
        description: string;
        /** Where the POST goes. Built on the server, so no route helper is needed here. */
        action: string;
        /** Whether the capability is available at all. */
        available: boolean;
        /** The sentence to show when it is not. Composed by the page, which knows the feature's name. */
        unavailableMessage: string;
        /** False when there is too little data yet — a different situation from «not available». */
        hasEnoughEvidence?: boolean;
        /** The sentence for that case. */
        insufficientEvidenceMessage?: string;
        /** The blocks to render, in order, once an answer arrives. Empty while there is none. */
        sections?: AiReadingSection[];
        /**
         * A flashed error from the server, if the last attempt failed.
         *
         * `retryable` is the server saying whether a second press could produce
         * a different outcome. Absent means yes — every screen that flashed an
         * error before this field existed was describing weather, and the
         * server is now explicit about the cases that are arithmetic. See
         * `AiRequestFailed::isRetryable()`.
         */
        error?: { message: string; retryable?: boolean } | null;
        /** Whether the payload that produced this reading was pseudonymised. */
        pseudonymised?: boolean;
        /** A line above the answer saying what it is a reading OF. */
        caption?: string | null;
        /** What the skeleton says while the request is in flight. */
        loadingLabel?: string;
        /** A unique id fragment, so two panels on one page have distinct headings. */
        headingId: string;
    }>(),
    {
        hasEnoughEvidence: true,
        insufficientEvidenceMessage: 'Ainda não há dados suficientes para uma leitura.',
        sections: () => [],
        error: null,
        pseudonymised: false,
        caption: null,
        loadingLabel: 'A interpretar os dados desta página…',
    },
);

const running = ref(false);
const dismissedError = ref(false);

/**
 * A fresh flash from the server means a fresh attempt, so a previously
 * dismissed error must not suppress the new one.
 */
watch(
    () => props.error,
    () => {
        dismissedError.value = false;
    },
);

const visibleError = computed(() => (dismissedError.value ? null : props.error));

const hasReading = computed(() => props.sections.length > 0);

/** The button may be pressed when the capability is on AND there is something to read. */
const canRun = computed(() => props.available && props.hasEnoughEvidence);

/**
 * Whether to offer «Tentar novamente» beside the error that is showing.
 *
 * A RETRY LINK IS A PROMISE, and for a deterministic failure it is a false one.
 * When the engine ran out of output budget, or the credential was rejected, or
 * the answer came back in a shape this application cannot read, the next press
 * reproduces the same failure exactly — and costs the school another request to
 * do it. The server is the only layer that knows which kind of failure this
 * was, so it says so, and this is the whole of what the panel does with it.
 */
const canRetry = computed(() => canRun.value && visibleError.value?.retryable !== false);

const buttonLabel = computed(() => {
    if (running.value) {
        return 'A analisar…';
    }

    return hasReading.value ? 'Pedir de novo' : props.title;
});

function run(): void {
    if (!canRun.value || running.value) {
        return;
    }

    // NOTHING FROM THE PAGE IS SENT BACK. The server rebuilds the figures from
    // the same read model it rendered them with; forwarding what the browser
    // holds would let a tampered client choose what the reading describes.
    router.post(
        props.action,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                running.value = true;
                dismissedError.value = true;
            },
            onFinish: () => {
                running.value = false;
            },
        },
    );
}
</script>

<template>
    <section :aria-labelledby="headingId" class="rounded-lg border border-border p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 :id="headingId" class="flex items-center gap-2 text-sm font-semibold">
                    <Sparkles aria-hidden="true" class="size-4" />
                    {{ title }}
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">{{ description }}</p>
            </div>

            <button
                v-if="canRun"
                type="button"
                :disabled="running"
                class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md border border-border px-3 text-sm font-medium hover:bg-muted/40 disabled:opacity-50"
                @click="run"
            >
                <Sparkles aria-hidden="true" class="size-3.5" />
                {{ buttonLabel }}
            </button>
        </div>

        <!-- TWO UNAVAILABLE STATES, NOT ONE. «O plano não inclui» is for the
             school and never changes by waiting; «ainda não há dados» is for
             the teacher and fixes itself. Showing the same sentence for both
             sends one of them to the wrong person. -->
        <p v-if="!available" class="mt-3 rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground">
            {{ unavailableMessage }}
        </p>
        <p v-else-if="!hasEnoughEvidence" class="mt-3 rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground">
            {{ insufficientEvidenceMessage }}
        </p>

        <div aria-live="polite" :aria-busy="running">
            <!-- A skeleton rather than a spinner: the blocks that are coming are
                 visible as blocks while they load, so the page does not jump
                 when they arrive. -->
            <div v-if="running" class="mt-4 space-y-3">
                <div class="h-3 w-2/3 animate-pulse rounded bg-muted"></div>
                <div class="h-3 w-full animate-pulse rounded bg-muted"></div>
                <div class="h-3 w-5/6 animate-pulse rounded bg-muted"></div>
                <p class="pt-1 text-xs text-muted-foreground">{{ loadingLabel }}</p>
            </div>

            <div v-else-if="visibleError" class="mt-4 rounded-lg bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-400">
                <p>{{ visibleError.message }}</p>
                <button
                    v-if="canRetry"
                    type="button"
                    class="mt-2 inline-flex items-center gap-1.5 font-medium underline underline-offset-2"
                    @click="run"
                >
                    <RotateCcw aria-hidden="true" class="size-3" />
                    Tentar novamente
                </button>
            </div>

            <div v-else-if="hasReading" class="mt-4 space-y-4 border-t border-border/60 pt-4">
                <p v-if="caption" class="text-xs text-muted-foreground">{{ caption }}</p>

                <div v-for="section in sections" :key="section.title">
                    <h3 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ section.title }}</h3>
                    <p v-if="section.text" class="mt-1 text-sm">{{ section.text }}</p>
                    <ul v-else class="mt-1 space-y-1">
                        <li v-for="(item, index) in section.items" :key="index" class="text-sm">— {{ item }}</li>
                    </ul>
                </div>

                <AiDisclosure :pseudonymised="pseudonymised" />
            </div>
        </div>
    </section>
</template>
