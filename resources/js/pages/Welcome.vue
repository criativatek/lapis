<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import LandingBenefits from '@/components/landing/LandingBenefits.vue';
import LandingCustomEvaluation from '@/components/landing/LandingCustomEvaluation.vue';
import LandingFaq from '@/components/landing/LandingFaq.vue';
import LandingFeatures from '@/components/landing/LandingFeatures.vue';
import LandingFinalCta from '@/components/landing/LandingFinalCta.vue';
import LandingFooter from '@/components/landing/LandingFooter.vue';
import LandingHeader from '@/components/landing/LandingHeader.vue';
import LandingHero from '@/components/landing/LandingHero.vue';
import LandingHowItWorks from '@/components/landing/LandingHowItWorks.vue';
import LandingPricing from '@/components/landing/LandingPricing.vue';
import LandingProblem from '@/components/landing/LandingProblem.vue';
import LandingRules from '@/components/landing/LandingRules.vue';
import LandingSecurity from '@/components/landing/LandingSecurity.vue';
import type { LandingPlan } from '@/components/landing/types';

/**
 * The public landing page.
 *
 * A visitor who is already signed in still lands here — the header and both
 * calls to action swap to «Ir para o painel» instead of redirecting, which is
 * what somebody arriving from a shared link expects and the only shape that
 * cannot loop against the dashboard.
 *
 * The SEO tags are NOT here: Inertia SSR is off, so anything a crawler must
 * read lives in resources/views/app.blade.php. This <Head> only sets the tab
 * title, which the browser applies after hydration anyway.
 */

defineProps<{ plans: LandingPlan[] }>();

const page = usePage();
const authenticated = computed(() => page.props.auth.user !== null);

/*
 * THERE IS NO SMOOTH SCROLLING HERE, and that is deliberate.
 *
 * It was tried and removed: the page is roughly 13000px tall on a phone, so a
 * jump from the hero to «Perguntas» animated for over three seconds. Measured
 * on a touch-emulated viewport, the page was still travelling at 2.5s — which
 * a visitor reads as «the menu is broken», not as «this is elegant». A native,
 * instant jump is what an anchor is supposed to do, and it is the same on
 * every browser including Safari on iOS.
 */
</script>

<template>
    <Head title="LÁPIS — Mais tempo para ensinar" />

    <div class="min-h-screen bg-background text-foreground">
        <a
            href="#conteudo"
            class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[60] focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-primary-foreground"
        >
            Saltar para o conteúdo
        </a>

        <LandingHeader :authenticated="authenticated" />

        <main id="conteudo">
            <LandingHero :authenticated="authenticated" />
            <LandingProblem />
            <LandingHowItWorks />
            <LandingCustomEvaluation />
            <LandingFeatures />
            <LandingRules />
            <LandingBenefits />
            <LandingSecurity />
            <LandingPricing :plans="plans" />
            <LandingFaq />
            <LandingFinalCta :authenticated="authenticated" />
        </main>

        <LandingFooter :authenticated="authenticated" />
    </div>
</template>
