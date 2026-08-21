<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
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

/**
 * Smooth scrolling for the header's anchors, set on the scrolling element
 * itself and only while this page is mounted — a global `scroll-behavior`
 * would follow the teacher into the application, where long grids are paged
 * through with the keyboard and an animated jump is a nuisance.
 */
onMounted(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    document.documentElement.style.scrollBehavior = 'smooth';
});

onBeforeUnmount(() => {
    document.documentElement.style.removeProperty('scroll-behavior');
});
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
