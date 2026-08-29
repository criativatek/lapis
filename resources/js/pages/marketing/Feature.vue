<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { computed } from 'vue';
import LandingFinalCta from '@/components/landing/LandingFinalCta.vue';
import BenefitCard from '@/components/marketing/BenefitCard.vue';
import ColorBand from '@/components/marketing/ColorBand.vue';
import { featureFor } from '@/components/marketing/features';
import MarketingShell from '@/components/marketing/MarketingShell.vue';
import PageHero from '@/components/marketing/PageHero.vue';
import ScreenFrame from '@/components/marketing/ScreenFrame.vue';

/**
 * One of the five feature pages. The words come from features.ts by slug;
 * the <title> a crawler reads is rendered by the server from PublicPages, and
 * this <Head> only keeps the tab in agreement with it.
 */
const props = defineProps<{
    slug: string;
    /** Same string the server renders in <title>; with SSR on, this one wins. */
    seoTitle: string;
    contactEmail: string | null;
}>();

const feature = computed(() => featureFor(props.slug));
</script>

<template>
    <Head :title="seoTitle" />

    <MarketingShell v-slot="{ authenticated }" :contact-email="contactEmail">
        <PageHero
            :eyebrow="feature.eyebrow"
            :title="feature.title"
            :title-accent="feature.titleAccent"
            :lead="feature.lead"
            :image="feature.photo"
            :authenticated="authenticated"
        />

        <ColorBand tone="blue" :title="feature.claim">
            <ScreenFrame
                :src="feature.screenshot.src"
                :alt="feature.screenshot.alt"
                :width="feature.screenshot.width"
                :height="feature.screenshot.height"
                eager
            />
        </ColorBand>

        <ColorBand
            eyebrow="O que muda"
            title="Feito para a forma como os professores realmente trabalham."
        >
            <div class="grid gap-5 sm:grid-cols-2">
                <BenefitCard
                    v-for="(benefit, index) in feature.benefits"
                    :key="benefit.title"
                    :icon="benefit.icon"
                    :title="benefit.title"
                    :body="benefit.body"
                    :tone="
                        (['blue', 'amber', 'emerald', 'blue'] as const)[
                            index % 4
                        ]
                    "
                />
            </div>
        </ColorBand>

        <ColorBand
            tone="amber"
            eyebrow="Como funciona"
            title="Três passos, e fica montado."
        >
            <ol class="grid gap-6 sm:grid-cols-3">
                <li
                    v-for="(step, index) in feature.steps"
                    :key="step.title"
                    class="rounded-3xl bg-white p-6 card-soft"
                >
                    <span
                        aria-hidden="true"
                        class="flex size-10 items-center justify-center rounded-full bg-amber-500 clay-disc text-sm font-semibold text-white tabular-nums"
                    >
                        {{ index + 1 }}
                    </span>
                    <h3 class="mt-4 font-semibold tracking-tight text-balance">
                        {{ step.title }}
                    </h3>
                    <p
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ step.body }}
                    </p>
                </li>
            </ol>

            <nav
                aria-label="Páginas relacionadas"
                class="mt-10 flex flex-wrap gap-3"
            >
                <Link
                    v-for="link in feature.related"
                    :key="link.href"
                    :href="link.href"
                    class="group inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-sm font-medium ring-1 ring-amber-900/10 transition-colors hover:bg-amber-100 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {{ link.label }}
                    <ArrowRight
                        aria-hidden="true"
                        class="size-4 transition-transform duration-300 group-hover:translate-x-0.5"
                    />
                </Link>
            </nav>
        </ColorBand>

        <LandingFinalCta :authenticated="authenticated" />
    </MarketingShell>
</template>
