<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    ArrowRight,
    FileText,
    ListChecks,
    TrendingUp,
    UserRound,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import ScreenFrame from '@/components/marketing/ScreenFrame.vue';
import { PRO_HIGHLIGHTS, PRO_HIGHLIGHTS_SHORT } from './commercial';
import LandingSection from './LandingSection.vue';
import RevealOnScroll from './RevealOnScroll.vue';

/**
 * «Pro em destaque» — what the Análise da Avaliação (0.177.0) gives a teacher
 * who reads a class across the year.
 *
 * ONE COPY, TWO SIZES. Every word lives in `PRO_HIGHLIGHTS` (commercial.ts),
 * next to the prices and the comparison rows it must agree with. /planos shows
 * all five blocks; the landing passes `compact` and shows three, plus the way
 * to /planos. A page that repeated the whole section on both would be the
 * long, repetitive page this was asked not to be.
 *
 * NO ENTITLEMENT LOGIC HERE. Which plan carries the Análise is not copy: it is
 * `advanced_analytics`, and the comparison table derives its ✓ from the
 * seeder. This section only describes what the teacher gets.
 *
 * THE HONESTY LINE IS PART OF THE SECTION, NOT A FOOTNOTE. The Análise
 * describes notes and moments; it is not a diagnosis, it predicts nothing and
 * it proposes no measure. Said where the claims are, so nobody reads
 * «resultados a observar» as a verdict.
 *
 * THE PICTURE IS THE REAL PAGE, on /planos only: a capture of the comparison
 * with fictional data (see `PRO_HIGHLIGHTS.screenshot`), never a mock-up. The
 * landing's short version has none, so the home page does not grow.
 */

const props = withDefaults(defineProps<{ compact?: boolean }>(), {
    compact: false,
});

const ICONS: Record<string, Component> = {
    compare: ArrowLeftRight,
    student: UserRound,
    strengths: ListChecks,
    trends: TrendingUp,
    document: FileText,
};

const blocks = computed(() =>
    props.compact
        ? PRO_HIGHLIGHTS.blocks.filter((block) =>
              (PRO_HIGHLIGHTS_SHORT as readonly string[]).includes(block.key),
          )
        : PRO_HIGHLIGHTS.blocks,
);
</script>

<template>
    <LandingSection
        :id="compact ? 'pro-na-landing' : PRO_HIGHLIGHTS.id"
        :eyebrow="PRO_HIGHLIGHTS.eyebrow"
        :title="PRO_HIGHLIGHTS.title"
        :lead="compact ? PRO_HIGHLIGHTS.shortLead : PRO_HIGHLIGHTS.lead"
    >
        <ul
            class="grid gap-4 sm:gap-5"
            :class="
                compact ? 'md:grid-cols-3' : 'sm:grid-cols-2 lg:grid-cols-3'
            "
            :data-pro-highlights="compact ? 'compact' : 'full'"
        >
            <li
                v-for="(block, index) in blocks"
                :key="block.key"
                :data-highlight="block.key"
            >
                <RevealOnScroll :delay="Math.min(index, 4) * 70" class="h-full">
                    <div
                        class="flex h-full flex-col rounded-2xl border border-border/70 bg-card p-5 shadow-sm sm:p-6"
                    >
                        <span
                            aria-hidden="true"
                            class="flex size-10 items-center justify-center rounded-xl bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300"
                        >
                            <component :is="ICONS[block.key]" class="size-5" />
                        </span>
                        <h3
                            class="mt-4 text-[17px] leading-snug font-semibold tracking-tight text-balance"
                        >
                            {{ block.title }}
                        </h3>
                        <p
                            class="mt-2 mb-4 text-sm leading-relaxed text-pretty text-muted-foreground"
                        >
                            {{ block.sentence }}
                        </p>
                        <p
                            class="mt-auto border-t border-border/60 pt-3 text-[13px] leading-snug text-pretty text-foreground/80"
                        >
                            {{ block.evidence }}
                        </p>
                    </div>
                </RevealOnScroll>
            </li>

            <!-- The sixth cell of the full grid says where the section stops. -->
            <li v-if="!compact" data-highlight="honesty">
                <RevealOnScroll :delay="280" class="h-full">
                    <div
                        class="flex h-full items-center rounded-2xl border border-dashed border-border bg-muted/30 p-5 sm:p-6"
                    >
                        <p
                            class="text-sm leading-relaxed text-pretty text-muted-foreground"
                        >
                            {{ PRO_HIGHLIGHTS.honesty }}
                        </p>
                    </div>
                </RevealOnScroll>
            </li>
        </ul>

        <RevealOnScroll v-if="!compact">
            <figure class="mx-auto mt-10 max-w-4xl sm:mt-12">
                <ScreenFrame
                    :src="PRO_HIGHLIGHTS.screenshot.src"
                    :alt="PRO_HIGHLIGHTS.screenshot.alt"
                    :width="PRO_HIGHLIGHTS.screenshot.width"
                    :height="PRO_HIGHLIGHTS.screenshot.height"
                />
                <figcaption
                    class="mt-3 text-center text-xs text-muted-foreground"
                >
                    {{ PRO_HIGHLIGHTS.screenshot.caption }}
                </figcaption>
            </figure>
        </RevealOnScroll>

        <RevealOnScroll v-if="compact">
            <div
                class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-3"
                data-highlight="honesty"
            >
                <p
                    class="max-w-2xl text-sm leading-relaxed text-pretty text-muted-foreground"
                >
                    {{ PRO_HIGHLIGHTS.honesty }}
                </p>
                <Link
                    :href="`/planos#${PRO_HIGHLIGHTS.id}`"
                    class="group inline-flex items-center gap-1.5 text-sm font-medium text-blue-700 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:text-blue-300"
                >
                    {{ PRO_HIGHLIGHTS.cta }}
                    <ArrowRight
                        aria-hidden="true"
                        class="size-4 transition-transform duration-300 group-hover:translate-x-0.5"
                    />
                </Link>
            </div>
        </RevealOnScroll>
    </LandingSection>
</template>
